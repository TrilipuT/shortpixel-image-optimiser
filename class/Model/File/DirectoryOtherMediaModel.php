<?php
namespace ShortPixel\Model\File;
use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;
use ShortPixel\Notices\NoticeController as Notice;

use \ShortPixel\Model\File\DirectoryModel as DirectoryModel;

use ShortPixel\Controller\OptimizeController as OptimizeController;

// extends DirectoryModel. Handles Shortpixel_meta database table
// Replacing main parts of shortpixel-folder
class DirectoryOtherMediaModel extends DirectoryModel
{

  protected $id = -1; // if -1, this might not exist yet in Dbase. Null is not used, because that messes with isset

  protected $name;
  protected $status = 0;
  protected $fileCount = 0; // inherent onreliable statistic in dbase. When insert / batch insert the folder count could not be updated, only on refreshFolder which is a relative heavy function to use on every file upload. Totals are better gotten from a stat-query, on request.
  protected $updated = 0;
  protected $created = 0;
  protected $path_md5;

  protected $is_nextgen = false;
  protected $in_db = false;
  protected $is_removed = false;

  protected $stats;

  const DIRECTORY_STATUS_REMOVED = -1;
  const DIRECTORY_STATUS_NORMAL = 0;
  const DIRECTORY_STATUS_NEXTGEN = 1;

  /** Path or Folder Object, from SpMetaDao
  *
  */
  public function __construct($path)
  {

    if (is_object($path)) // Load directly via Database object, this saves a query.
    {
       $folder = $path;
       $path = $folder->path;

       parent::__construct($path);
       $this->loadFolder($folder);
    }
    else
    {
      parent::__construct($path);
      $this->loadFolderbyPath($path);
    }
  }


  public function get($name)
  {
     if (property_exists($this, $name))
      return $this->$name;

     return null;
  }

  public function set($name, $value)
  {
     if (property_exists($this, $name))
     {
        $this->name = $value;
        return true;
     }

     return null;
  }

  public function getStats()
  {
    if (is_null($this->stats))
    {
      global $wpdb;

      $sql = "SELECT SUM(CASE WHEN status = 2 THEN 1 ELSE 0 END) Optimized, "
          . "SUM(CASE WHEN status = 0 THEN 1 ELSE 0 END) Waiting, count(*) Total "
          . "FROM  " . $wpdb->prefix . "shortpixel_meta "
          . "WHERE folder_id = %d";
      $sql = $wpdb->prepare($sql, $this->id);

      $res = $wpdb->get_row($sql);
      $this->stats = $res;

    }

    return $this->stats;
  }

  public function save()
  {
    // Simple Update
      global $wpdb;
        $data = array(
        //    'id' => $this->id,
            'status' => $this->status,
            'file_count' => $this->fileCount,
            'ts_updated' => $this->timestampToDB($this->updated),
            'name' => $this->name,
            'path' => $this->getPath(),
        );
        $format = array('%d', '%d', '%s', '%s', '%s');
        $table = $wpdb->prefix . 'shortpixel_folders';

        $is_new = false;

        if ($this->in_db) // Update
        {
            $wpdb->update($table, $data, array('id' => $this->id), $format);
        }
        else // Add new
        {
            $this->id = $wpdb->insert($table, $data);
        }


        if ($is_new) // reloading because action can create a new DB-entry, which will not be reflected (in id )
        $this->loadFolderByPath($this->getPath());

  }

  public function delete()
  {
      $id = $this->id;
      if (! $this->in_db)
      {
         Log::addError('Trying to remove Folder without being in the database (in_db false) ' . $id, $this->getPath());
      }

      global $wpdb;

      // @todo This should be query here.
      $sql = 'DELETE FROM ' . $wpdb->prefix . 'shortpixel_folders where id = %d';
      $sql = $wpdb->prepare($sql, $this->id);

      $result = $wpdb->query($sql);

  }

  public function isRemoved()
  {
      if ($this->is_removed)
        return true;
      else
        return false;
  }

  /** Updates the updated variable on folder to indicating when the last file change was made
  * @return boolean  True if file were changed since last update, false if not
  */
  public function updateFileContentChange()
  {
      if (! $this->exists() )
        return false;

      $old_time = $this->updated;

      $time = $this->recurseLastChangeFile();
      $this->updated = $time;
      $this->save();

      if ($old_time !== $time)
        return true;
      else
        return false;
  }



  /** Crawls the folder and check for files that are newer than param time, or folder updated
  * Note - last update timestamp is not updated here, needs to be done separately.
  */
  public function refreshFolder(bool $force = false)
  {
      if ($force === false)
      {
        $time = $this->updated;
      }
      else
      {

        $time = 0; //force refresh of the whole.
      }

      if ($this->id <= 0)
      {
        Log::addWarn('FolderObj from database is not there, while folder seems ok ' . $this->getPath() );
        return false;
      }
      elseif (! $this->exists())
      {
        Notice::addError( sprintf(__('Folder %s does not exist! ', 'shortpixel-image-optimiser'), $this->getPath()) );
        return false;
      }
      elseif (! $this->is_writable())
      {
        Notice::addWarning( sprintf(__('Folder %s is not writeable. Please check permissions and try again.','shortpixel-image-optimiser'),$this->getPath()) );
        return false;
      }

      $fs = \wpSPIO()->filesystem();
      $filter = ($time > 0)  ? array('date_newer' => $time) : array();
      $filter['exclude_files'] = array('.webp', '.avif');

      $files = $fs->getFilesRecursive($this, $filter);

      \wpSPIO()->settings()->hasCustomFolders = time(); // note, check this against bulk when removing. Custom Media Bulk depends on having a setting.
      $result = $this->batchInsertImages($files);

      $this->stats = null; //reset
      $stats = $this->getStats();
      $this->fileCount = $stats->Total;

      $this->save();

  }

    private function recurseLastChangeFile($mtime = 0)
    {
      $ignore = array('.','..');
      $path = $this->getPath();

      $files = scandir($path);
      $files = array_diff($files, $ignore);

      $mtime = max($mtime, filemtime($path));

      foreach($files as $file) {

          $filepath = $path . $file;

          if (is_dir($filepath)) {
              $mtime = max($mtime, filemtime($filepath));
              $subDirObj = new DirectoryOtherMediaModel($filepath);
              $subdirtime = $subDirObj->recurseLastChangeFile($mtime);
              if ($subdirtime > $mtime)
                $mtime = $subdirtime;
          }
      }
      return $mtime;
    }

    private function timestampToDB($timestamp)
    {
        return date("Y-m-d H:i:s", $timestamp);
    }

    private function DBtoTimestamp($date)
    {
        return strtotime($date);
    }

  /** This function is called by OtherMediaController / RefreshFolders. Other scripts should not call it
  * @private
  * @param Array of CustomMediaImageModel stubs.
  */
  private function batchInsertImages($files) {

      global $wpdb;
      /*$sqlCleanup = "DELETE FROM {$this->db->getPrefix()}shortpixel_meta WHERE folder_id NOT IN (SELECT id FROM {$this->db->getPrefix()}shortpixel_folders)";
      $wpdb->query($sqlCleanup); */

      $values = array();

      $optimizeControl = new OptimizeController();
      $fs = \wpSPIO()->filesystem();

      foreach($files as $fileObj)
      {
          $imageObj = $fs->getCustomStub($fileObj->getFullPath(), false);
          $imageObj->setFolderId($this->id);

          //$imageObj = $fs->getCustomStub($files, false);
          if ($imageObj->get('in_db') == true) // already exists
            continue;
          elseif ($imageObj->isProcessable())
          {
             $imageObj->saveMeta();
             Log::addTemp('Batch New : new File saved');
             if (\wpSPIO()->settings()->autoMediaLibrary == 1)
             {
                Log::addTemp('adding item to queue ' . $imageObj->get('id'));
                $optimizeControl->addItemToQueue($imageObj);
             }
          }

      }

      /*$status = (\wpSPIO()->settings()->autoMediaLibrary == 1) ? ShortPixelMeta::FILE_STATUS_PENDING : ShortPixelMeta::FILE_STATUS_UNPROCESSED; */
    /*  $created = date("Y-m-d H:i:s");

      foreach($files as $file) {
          $filepath = $file->getFullPath();
          $filename = $file->getFileName();

          array_push($values, $this->id, $filepath, $filename, md5($filepath), $status, $created);
          $placeholders[] = $format;

          if($i % 500 == 499) {
              $query = $sql;
              $query .= implode(', ', $placeholders);
              $wpdb->query( $wpdb->prepare("$query ", $values));

              $values = array();
              $placeholders = array();
          }
          $i++;
      }
      if(count($values) > 0) {
        $query = $sql;
        $query .= implode(', ', $placeholders);
        $result = $wpdb->query( $wpdb->prepare("$query ", $values) );
        Log::addDebug('Q Result', array($result, $wpdb->last_error));
        //$this->db->query( $this->db->prepare("$query ", $values));
        return $result;
      } */
  }


    private function loadFolderByPath($path)
    {
        //$folders = self::getFolders(array('path' => $path));
         global $wpdb;

         $sql = 'SELECT * FROM ' . $wpdb->prefix . 'shortpixel_folders where path = %s ';
         $sql = $wpdb->prepare($sql, $path);

        $folder = $wpdb->get_row($sql);
        if (! is_object($folder))
          return false;
        else
        {
          $this->loadFolder($folder);
          $this->in_db = true; // exists in database
          return true;
        }
    }

    /** Loads from database into model, the extra data of this model. */
    private function loadFolder($folder)
    {

        $class = get_class($folder);

        $this->id = $folder->id;

        if ($this->id > 0)
         $this->in_db = true;

        $this->updated = isset($folder->ts_updated) ? $this->DBtoTimestamp($folder->ts_updated) : time();
        $this->created = isset($folder->ts_created) ? $this->DBtoTimestamp($folder->ts_created) : time();
        $this->fileCount = isset($folder->file_count) ? $folder->file_count : 0; // deprecated, do not rely on.


        if (strlen($folder->name) == 0)
          $this->name = basename($folder->path);
        else
          $this->name = $folder->name;

        $this->status = $folder->status;

        if ($this->status == -1)
          $this->is_removed = true;

        if ($this->status == self::DIRECTORY_STATUS_NEXTGEN)
        {
          $this->is_nextgen = true;
        }

        do_action('shortpixel/othermedia/folder/load', $this->id, $this);


    }

}