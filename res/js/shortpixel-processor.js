
//Module pattern
window.ShortPixelProcessor =
{
  //  spp: {},
    isActive: false,
    interval: 2000,
    screen: null, // UI Object
    tooltip: null,
    isBulkPage: false,
    localSecret: null,
    remoteSecret: null,
    worker: null,
    timer: null,
    nonce: [],
    qStatus: {
       4:  'QUEUE_WAITING',
       10: 'QUEUE_EMPTY',
    },

    Load: function()
    {
        this.isBulkPage = ShortPixelProcessorData.isBulkPage;
        this.localSecret = localStorage.bulkSecret;
        this.remoteSecret = ShortPixelProcessorData.bulkSecret;

        this.nonce['process'] = ShortPixelProcessorData.nonce_process;
        this.nonce['exit'] = ShortPixelProcessorData.nonce_process;
        this.nonce['itemview'] = ShortPixelProcessorData.nonce_itemview;
        this.nonce['ajaxRequest'] = ShortPixelProcessorData.nonce_ajaxrequest;

        //console.log(ShortPixelProcessorData);

        if (this.remoteSecret == false || this.isBulkPage) // if remoteSecret is false, we are the first process. Take it.
        {
           this.localSecret = this.remoteSecret = Math.random().toString(36).substring(7);
           localStorage.bulkSecret = this.localSecret;
           this.isActive = true;
        }
        else if (this.remoteSecret == this.localSecret) // There is a secret, we are the processor.
        {
           this.isActive = true;
        }
        else
        {
           console.debug('Processor not active - ' + this.remoteSecret + ' - ' + this.localSecret);
        }

        // Always load worker, also used for UI actions.
        this.LoadWorker();

        if (this.isActive)
        {
          //  console.debug('loading worker');

            this.RunProcess();
        }

        if (typeof ShortPixelScreen == 'undefined')
        {
           console.error('Missing Screen for feedback!');

        }
        else
          this.screen = new ShortPixelScreen({}, this);

        this.tooltip = new ShortPixelToolTip();


    },
    LoadWorker: function()
    {
        if (window.Worker)
        {
            console.log('Starting Worker');
            var ajaxURL = ShortPixel.AJAX_URL;
            var nonce = '';

            this.worker = new Worker(ShortPixelProcessorData.workerURL);
            this.worker.postMessage({'action': 'init', 'data' : [ajaxURL, this.localSecret]});
            this.worker.onmessage = this.CheckResponse.bind(this);

            window.addEventListener('beforeunload', this.ShutDownWorker.bind(this));
            //window.addEventListener('shortpixel.loadItemView', this.LoadItemView.bind(this));
            //window.addEventListener('shortpixel.')
        }
    },
    ShutDownWorker: function()
    {
        if (this.worker === null) // worker already shut / not loaded
          return false;

        console.log('Shutting down Worker');
        this.worker.postMessage({'action' : 'shutdown', 'nonce': this.nonce['exit'] });
        this.worker.terminate();
        this.worker = null;
        window.removeEventListener('beforeunload', this.ShutDownWorker.bind(this));
        window.removeEventListener('shortpixel.loadItemView', this.LoadItemView.bind(this));
    },
    Process: function()
    {
        //$(document).on timeout - check function.
        //console.log(this);
        if (this.worker === null)
           this.LoadWorker(); // JIT worker loading

        this.tooltip.DoingProcess();
        this.worker.postMessage({action: 'process', 'nonce' : this.nonce['process']});

    },
    RunProcess: function()
    {
        if (this.timer)
          window.clearTimeout(this.timer);

        this.timer = window.setTimeout(this.Process.bind(this), this.interval);
    },
    StopProcessing: function()
    {
         window.clearTimeout(this.timer);
    },
    CheckResponse: function(message)
    {

      var data = message.data;

      if (data.status == false)
      {
          console.log('Network Error ' + data.message);
      }
      else if (data.status == true && data.response) // data status is from shortpixel worker, not the response object
      {
          if (! this.screen)
          {
             console.error('Missing screen - can\'t report results');
             return false;
          }

          var response = data.response;
          if ( response.callback)
          {
              console.log('Running callback : ' + response.callback);
              var event = new CustomEvent(response.callback, {detail: response});
              window.dispatchEvent(event);
              return; // no Handle on callback.
          }

           // Check the screen if we are custom or media ( or bulk ) . Check the responses for each of those.
           if (this.screen.isCustom && typeof response.custom == 'object' && response.custom !== null)
           {
               this.HandleResponse(response.custom, 'custom');
           }
           if (this.screen.isMedia && typeof response.media == 'object' && response.media !== null)
           {
                this.HandleResponse(response.media, 'media');
           }

      }

    },
    HandleResponse: function(response, type)
    {
        if (response.has_error == true)
        {
           this.screen.handleError(response.message);
        }

        // Perhaps if optimization, the new stats and actions should be generated server side?

         // If there are items, give them to the screen for display of optimization, waiting status etc.
         if (typeof response.results !== 'undefined' && response.results !== null)
         {
             for (i = 0; i < response.results.length; i++)
             {
                var imageResult = response.results[i];
                this.screen.handleImage(response.results[i], type);
             }
         }
         if (typeof response.result !== 'undefined' && response.result !== null)
         {
              this.screen.handleImage(response, type); // whole response here is single item.
         }


         // Queue status?
         if (response.stats)
         {
            this.tooltip.RefreshStats(response.stats);
         }

         if (response.result == null && response.results == null)
         {
             if (this.qStatus[response.status] == 'QUEUE_WAITING')
             {
                this.RunProcess(); // run another queue with timeout
             }
             else if (this.qStatus[response.status] == 'QUEUE_EMPTY')
             {
                 console.debug('Processor: Empty Queue');
                 this.tooltip.ProcessEnd();
                 this.StopProcessing();
             }
         }

         // Check for errors like Queue / Key / Maintenance / etc  (is_error true, pass message to screen)


         // If all is fine, there is more in queue, enter back into queue.
    },
    LoadItemView: function(data)
    {
  //    var data = event.detail;
      var nonce = this.nonce['itemview'];
      this.worker.postMessage({action: 'getItemView', 'nonce' : this.nonce['itemview'], 'data': { 'id' : data.id, 'type' : data.type, 'callback' : 'shortpixel.RenderItemView'}});
    },
    AjaxRequest: function(data)
    {
       var localWorker = false;

     // { 'id' : id, 'type' : type, 'callback' : 'shortpixel.RenderItemView'}
       this.worker.postMessage({action: 'ajaxRequest', 'nonce' : this.nonce['ajaxRequest'], 'data': data });

       //this.runProcess();

    }


    //re/turn spp;
}
