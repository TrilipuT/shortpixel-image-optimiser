<?php
namespace ShortPixel\Controller;
use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;

use ShortPixel\Model\File\DirectoryModel as DirectoryModel;
use ShortPixel\Model\File\FileModel as FileModel;

use ShortPixel\Model\Image\MediaLibraryModel as MediaLibraryModel;
use ShortPixel\Model\Image\MediaLibraryThumbnailModel as MediaLibraryThumbnailModel;
use ShortPixel\Model\Image\CustomImageModel as CustomImageModel;

/** Controller for FileSystem operations
*
* This controller is used for -compound- ( complex ) FS operations, using the provided models File en Directory.
* USE via \wpSPIO()->filesystem();
*/
Class FileSystemController extends \ShortPixel\Controller
{
    protected $env;

    public function __construct()
    {
      $this->env = wpSPIO()->env();

    }

    /** Get FileModel for a certain path. This can exist or not
    *
    * @param String Path Full Path to the file
    * @return FileModel FileModel Object. If file does not exist, not all values are set.
    */
    public function getFile($path)
    {
      return new FileModel($path);
    }

    /** Get MediaLibraryModel for a Post_id */
    public function getMediaImage(int $id)
    {
        $filepath = get_attached_file($id);
        //$filepath = apply_filters('shortpixel_get_attached_file', $filepath, $id);

        // Somehow get_attached_file can return other random stuff.
        if ($filepath === false || strlen($filepath) == 0)
          return false;

        $imageObj = new MediaLibraryModel($id, $filepath);
        return $imageObj;
    }

    public function getCustomImage(int $id)
    {
        $imageObj = new CustomImageModel($id);
        return $imageObj;
    }

    /** Gets a custom Image Model without being in the database. This is used to check if path is a proper customModel path ( not mediaLibrary ) and see if the file should be included per excusion rules */
    public function getCustomStub(string $path, bool $load = true)
    {
        $imageObj = new CustomImageModel(0);
        $imageObj->setStub($path, $load);
        return $imageObj;
    }

    /** Generic function to get the correct image Object, to prevent many switches everywhere */
    public function getImage(int $id, string $type)
    {
      $imageObj = null;
      Log::addTemp('FSControl GetImage: ' . $id . ' ' . $type);

      if ($type == 'media')
        $imageObj = $this->getMediaImage($id);
      elseif($type == 'custom')
        $imageObj = $this->getCustomImage($id);
      else
        Log::addError('FileSystemController GetImage - no correc type given: ' . $type);

      return $imageObj;

    }

    /** Get FileModel for a mediaLibrary post_id .
    *
    * This function exists to put get_attached_file to plugin control
    * Externals / Interals maybe filter it.
    *
    * @param $id Attachement ID for the media library item
    * @return FileModel returns a FileModel file.
    * @todo This function will be more at home in a medialibrary_model
    */
  /*  public function getAttachedFile($id)
    {
        $filepath = get_attached_file($id);
        // same signature as wordpress' filter. Only for this plugin.
        $filepath = apply_filters('shortpixel_get_attached_file', $filepath, $id);

        return new FileModel($filepath);

    } */

    /* wp_get_original_image_path with specific ShortPixel filter
     */
    public function getOriginalImage($id)
    {
      $filepath = \wp_get_original_image_path($id);
      $filepath = apply_filters('shortpixel_get_original_image_path', $filepath, $id);
      return new MediaLibraryThumbnailModel($filepath, $id, 'original');
    }

    /** Get DirectoryModel for a certain path. This can exist or not
    *
    * @param String $path Full Path to the Directory.
    * @return DirectoryModel Object with status set on current directory.
    */
    public function getDirectory($path)
    {
      return new DirectoryModel($path);
    }

    /** Get the BackupLocation for a FileModel. FileModel should be not a backup itself or it will recurse
    *
    *  For now this function should only be used for *new* backup files. Retrieving backup files via this method
    *  doesn't take into account legacy ways of storage.
    *
    * @param FileModel $file FileModel with file that needs a backup.
    * @return DirectoryModel | Boolean DirectoryModel pointing to the backup directory. Returns false if the directory could not be created, or initialized.
    */
    public function getBackupDirectory(FileModel $file)
    {
      $wp_home = get_home_path();
      $filepath = $file->getFullPath();

      if ($file->is_virtual())
      {
         $filepath = apply_filters('shortpixel/file/virtual/translate', $filepath, $file);
      }

      // Implement this code better here.
      $backup_subdir = \ShortPixelMetaFacade::returnSubDir($filepath);

/* from fileModel:
      $backup_dir = str_replace($fs->getWPAbsPath(), "", $this->directory->getPath());
      $backupDirectory = SHORTPIXEL_BACKUP_FOLDER . '/' . $backup_dir;
      $directory = new DirectoryModel($backupDirectory);
*/
      $backup_fulldir = SHORTPIXEL_BACKUP_FOLDER . '/' . $backup_subdir;
      Log::addDebug('Get File BackupDirectory' . $backup_fulldir);

      $directory = $this->getDirectory($backup_fulldir);

      $directory = apply_filters("shortpixel/file/backup_folder", $directory, $file);

      if ($directory->check())
        return $directory;
      else {
        return false;
      }
    }

    /** Get the base folder from where custom paths are possible (from WP-base / sitebase)

    */
    public function getWPFileBase()
    {
      if(\wpSPIO()->env()->is_mainsite) {
          $path = (string) $this->getWPAbsPath();
      } else {
          $up = wp_upload_dir();
          $path = realpath($up['basedir']);
      }
      $dir = $this->getDirectory($path);
      if (! $dir->exists())
        Log::addWarn('getWPFileBase - Base path doesnt exist');

      return $dir;

    }

    /** This function returns the WordPress Basedir for uploads ( without date and such )
    * Normally this would point to /wp-content/uploads.
    * @returns DirectoryModel
    */
    public function getWPUploadBase()
    {
      $upload_dir = wp_upload_dir(null, false);

      return $this->getDirectory($upload_dir['basedir']);
    }

    /** This function returns the Absolute Path of the WordPress installation where the **CONTENT** directory is located.
    * Normally this would be the same as ABSPATH, but there are installations out there with -cough- alternative approaches
    * @returns DirectoryModel  Either the ABSPATH or where the WP_CONTENT_DIR is located
    */
    public function getWPAbsPath()
    {
        $wpContentAbs = str_replace( 'wp-content', '', WP_CONTENT_DIR);
        if (ABSPATH == $wpContentAbs)
          $abspath = ABSPATH;
        else
          $abspath = $wpContentAbs;

        $abspath = apply_filters('shortpixel/filesystem/abspath', $abspath );

        return $this->getDirectory($abspath);
    }

    /** Not in use yet, do not use. Future replacement. */
    public function createBackUpFolder($folder = SHORTPIXEL_BACKUP_FOLDER)
    {

    }

    /** Utility function that tries to convert a file-path to a webURL.
    *
    * If possible, rely on other better methods to find URL ( e.g. via WP functions ).
    */
    public function pathToUrl(FileModel $file)
    {
      $filepath = $file->getFullPath();
      $directory = $file->getFileDir();

      // stolen from wp_get_attachment_url
      if ( ( $uploads = wp_get_upload_dir() ) && (false === $uploads['error'] || strlen(trim($uploads['error'])) == 0  )  ) {
            // Check that the upload base exists in the file location.
            if ( 0 === strpos( $filepath, $uploads['basedir'] ) ) {
                // Replace file location with url location.
                $url = str_replace( $uploads['basedir'], $uploads['baseurl'], $filepath );
            } elseif ( false !== strpos( $filepath, 'wp-content/uploads' ) ) {
                // Get the directory name relative to the basedir (back compat for pre-2.7 uploads)
                $url = trailingslashit( $uploads['baseurl'] . '/' . _wp_get_attachment_relative_path( $filepath ) ) . wp_basename( $filepath );
            } else {
                // It's a newly-uploaded file, therefore $file is relative to the basedir.
                $url = $uploads['baseurl'] . "/$filepath";
            }
        }

        $wp_home_path = (string) $this->getWPAbsPath();
        // If the whole WP homepath is still in URL, assume the replace when wrong ( not replaced w/ URL)
        // This happens when file is outside of wp_uploads_dir
        if (strpos($url, $wp_home_path) !== false)
        {
          // This is SITE URL, for the same reason it should be home_url in FILEMODEL. The difference is when the site is running on a subdirectory
          // ** This is a fix for a real-life issue, do not change if this causes issues, another fix is needed then.
          $home_url = trailingslashit(get_site_url());
          $url = str_replace($wp_home_path, $home_url, $filepath);
        }

        // can happen if there are WP path errors.
        if (is_null($url))
          return false;

        $parsed = parse_url($url); // returns array, null, or false.

        // Some hosts set the content dir to a relative path instead of a full URL. Api can't handle that, so add domain and such if this is the case.
        if ( !isset($parsed['scheme']) ) {//no absolute URLs used -> we implement a hack

           if (isset($parsed['host'])) // This is for URL's for // without http or https. hackhack.
           {
             $scheme = is_ssl() ? 'https:' : 'http:';
             return $scheme. $url;
           }
           else
           {
           // From Metafacade. Multiple solutions /hacks.
              $home_url = trailingslashit((function_exists("is_multisite") && is_multisite()) ? trim(network_site_url("/")) : trim(home_url()));
              return $home_url . ltrim($url,'/');//get the file URL
           }
        }

        if (! is_null($parsed) && $parsed !== false)
          return $url;

        return false;
    }

    public function checkURL($url)
    {
        if (! $this->pathIsURL($url))
        {
           //$siteurl = get_option('siteurl');
           if (strpos($url, get_site_url()) == false)
           {
              $url = get_site_url(null, $url);

           }
        }

        return apply_filters('shortpixel/filesystem/url', $url);
    }

    /** Utility function to check if a path is an URL
    *  Checks if this path looks like an URL.
    * @param $path String  Path to check
    * @return Boolean If path seems domain.
    */
    public function pathIsUrl($path)
    {
      $is_http = (substr($path, 0, 4) == 'http') ? true : false;
      $is_https = (substr($path, 0, 5) == 'https') ? true : false;
      $is_neutralscheme = (substr($path, 0, 2) == '//') ? true : false; // when URL is relative like //wp-content/etc
      $has_urldots = (strpos($path, '://') !== false) ? true : false; // Like S3 offloads

      if ($is_http || $is_https || $is_neutralscheme || $has_urldots)
        return true;
      else
        return false;
    }

    /** Sort files / directories in a certain way.
    * Future dev to include options via arg.
    */
    public function sortFiles($array, $args = array() )
    {
        if (count($array) == 0)
          return $array;

        // what are we sorting.
        $class = get_class($array[0]);
        $is_files = ($class == 'ShortPixel\FileModel') ? true : false; // if not files, then dirs.

        usort($array, function ($a, $b) use ($is_files)
            {
              if ($is_files)
                return strcmp($a->getFileName(), $b->getFileName());
              else {
                return strcmp($a->getName(), $b->getName());
              }
            }
        );

        return $array;

    }

    public function downloadFile($url, $destinationPath)
    {
      $downloadTimeout = max(SHORTPIXEL_MAX_EXECUTION_TIME - 10, 15);
      $fs = \wpSPIO()->filesystem();
    //  $fs = \wpSPIO()->fileSystem();
      $destinationFile = $fs->getFile($destinationPath);

      $args_for_get = array(
        'stream' => true,
        'filename' => $destinationPath,
      );

      $response = wp_remote_get( $url, $args_for_get );

      if(is_wp_error( $response )) {
        Log::addError('Download file failed', array($url, $response->get_error_messages(), $response->get_error_codes() ));

        // Try to get it then via this way.
        $response = download_url($url, $downloadTimeout);
        if (!is_wp_error($response)) // response when alright is a tmp filepath. But given path can't be trusted since that can be reason for fail.
        {
          $tmpFile = $fs->getFile($response);
          $result = $tmpFile->move($destinationFile);

        } // download_url ..
        else {
          Log::addError('Secondary download failed', array($url, $response->get_error_messages(), $response->get_error_codes() ));
        }
      }
      else { // success, at least the download.
          $destinationFile = $fs->getFile($response['filename']);
      }

      Log::addDebug('Remote Download attempt result', array($url, $destinationPath));
      if ($destinationPath->exists())
        return true;
      else
        return false;
    }

    /** Get all files from a directory tree, starting at given dir.
    * @param DirectoryModel $dir to recursive into
    * @param Array $filters Collection of optional filters as accepted by FileFilter in directoryModel
    * @return Array Array of FileModel Objects
     **/
    public function getFilesRecursive(DirectoryModel $dir, $filters = array() )
    {
        $fileArray = array();

        if (! $dir->exists())
          return $fileArray;

        $files = $dir->getFiles($filters);
        $fileArray = array_merge($fileArray, $files);

        $subdirs = $dir->getSubDirectories();

        foreach($subdirs as $subdir)
        {
             $fileArray = array_merge($fileArray, $this->getFilesRecursive($subdir, $filters));
        }

        return $fileArray;
    }


}
