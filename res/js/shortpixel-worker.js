

onmessage = function(e)
{

  var action = e.data.action;
  var data = e.data.data;
  var nonce = e.data.nonce;
  var isBulk = false;

  SpWorker.nonce = nonce;

  if (typeof e.data.isBulk !== 'undefined')
     isBulk = e.data.isBulk;

  switch(action)
  {
     case 'init':
        SpWorker.Init(data[0], data[1], isBulk);
     break;
     case 'shutdown':
        SpWorker.ShutDown();
     break;
     case 'process':
       SpWorker.Process(data);
     break;
     case 'getItemView':
       SpWorker.GetItemView(data);
     break;
     case 'ajaxRequest':
      SpWorker.AjaxRequest(data);
     break;
  }


  console.log('action : ' + action);


}

SpWorker = {
   ajaxUrl: null,
   action: 'shortpixel_image_processing',
   secret: null,
   nonce: null,
   isBulk: false,

   Fetch: async function (data)
   {
    //  console.log(fetch);
/*      var data = {
         action: this.action,
         'bulk-secret': this.secret,
         nonce: this.nonce,
      }; */

      var params = new URLSearchParams();
      params.append('action', this.action);
      params.append('bulk-secret', this.secret);
      params.append('nonce', this.nonce);
      params.append('isBulk', this.isBulk);

      if (typeof data !== 'undefined' && typeof data == 'object')
      {
         for(key in data)
             params.append(key, data[key]);
      }

      var response = await fetch(this.ajaxUrl, {
          'method': 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
          },
          body: params.toString(),
      });

      if (response.ok)
      {
        console.log('response ok');
          var json = await response.json();

          postMessage({'status' : true, response: json});
      }
      else
      {
          postMessage({'status' : false, message: response.status});
      }
   },
   Init: function(url, secret, isBulk)
   {
        this.ajaxUrl = url;
        this.secret = secret;
        this.isBulk = isBulk;
   },
   ShutDown: function()
   {
       this.action ='shortpixel_exit_process';
       this.Fetch();
   },
   GetItemView: function(data)
   {
      this.action = 'shortpixel_get_item_view';
      this.Fetch(data);
   },
   AjaxRequest: function(data)
   {
      this.action = 'shortpixel_ajaxRequest';
      this.Fetch(data);
   },
   Process: function(data)
   {
      this.action = 'shortpixel_image_processing';
      this.Fetch(data);
   }


}
