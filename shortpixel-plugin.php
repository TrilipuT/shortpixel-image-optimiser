<?php
namespace ShortPixel;
use ShortPixel\ShortPixelLogger as Log;

/** Plugin class
* This class is meant for: WP Hooks, init of runtime and Controller Routing.

*/
class ShortPixelPlugin
{
  static $instance;
  private $paths = array('class', 'class/controller', 'class/external');

  public function __construct()
  {
      $this->initRuntime();
      $this->initHooks();
  }

  /** Create instance. This should not be needed to call anywhere else than main plugin file **/
  public static function getInstance()
  {
    if (is_null(self::$instance))
    {
      self::$instance = new shortPixelPlugin();
    }
    return self::$instance;
  }


  public function initRuntime()
  {
      $plugin_path = plugin_dir_path(SHORTPIXEL_PLUGIN_FILE);
      foreach($this->paths as $short_path)
      {
        $directory_path = realpath($plugin_path . $short_path);

        if ($directory_path !== false)
        {
          $it = new \DirectoryIterator($directory_path);
          foreach($it as $file)
          {
            $file_path = $file->getRealPath();

            if ($file->isFile() && pathinfo($file_path, PATHINFO_EXTENSION) == 'php')
            {
              require_once($file_path);
            }
          }
        }
      }
  }

  /** Hooks for all WordPress related hooks
  */
  public function initHooks()
  {
      add_action('admin_menu', array($this,'admin_pages'));
      add_action('admin_enqueue_scripts', array($this, 'admin_scripts'));
  }

  public function admin_pages()
  {
      // settings page
      add_options_page( __('ShortPixel Settings','shortpixel-image-optimiser'), 'ShortPixel', 'manage_options', 'wp-shortpixel-settings', array($this, 'route'));
  }

  /** All scripts should be registed, then enqueued here
  *
  * Not all those registered must be enqueued however.
  */
  public function admin_scripts()
  {
    // FileTree in Settings
    wp_register_style('sp-file-tree', plugins_url('/res/css/sp-file-tree.min.css',SHORTPIXEL_PLUGIN_FILE),array(), SHORTPIXEL_IMAGE_OPTIMISER_VERSION );
    wp_register_script('sp-file-tree', plugins_url('/res/js/sp-file-tree.min.js',SHORTPIXEL_PLUGIN_FILE) );

    wp_register_style('shortpixel-admin', plugins_url('/res/css/shortpixel-admin.css', SHORTPIXEL_PLUGIN_FILE),array(), SHORTPIXEL_IMAGE_OPTIMISER_VERSION );

    wp_register_style('shortpixel', plugins_url('/res/css/short-pixel.min.css',SHORTPIXEL_PLUGIN_FILE), array(), SHORTPIXEL_IMAGE_OPTIMISER_VERSION);
    //modal - used in settings for selecting folder
    wp_register_style('shortpixel-modal', plugins_url('/res/css/short-pixel-modal.min.css',SHORTPIXEL_PLUGIN_FILE), array(), SHORTPIXEL_IMAGE_OPTIMISER_VERSION);

  }

  /** Load Style via Route, on demand */
  public function load_style($name)
  {
    if (wp_style_is($name, 'registered'))
    {
      wp_enqueue_style($name);
    }
    else {
      Log::addWarn("Style $name was asked for, but not registered");
    }
  }

  /** Load Style via Route, on demand */
  public function load_script($name)
  {
    if (wp_script_is($name, 'registered'))
    {
      wp_enqueue_script($name);
    }
    else {
      Log::addWarn("Script $name was asked for, but not registered");
    }
  }

  /** Route, based on the page slug
  *
  * Principially all page controller should be routed from here.
  */
  public function route()
  {
      global $plugin_page;
      global $shortPixelPluginInstance; //brrr @todo Find better solution for this some day.
      $action = 'load'; // generic action on controller.
      $controller = false;

      switch($plugin_page)
      {
          case 'wp-shortpixel-settings':
            $this->load_style('shortpixel-admin');
            $this->load_style('shortpixel');
            $this->load_style('shortpixel-modal');
            $controller = \shortPixelTools::namespaceit("SettingsController");
          break;
      }

      if ($controller !== false)
      {
        $c = new $controller();
        $c->setShortPixel($shortPixelPluginInstance);
        $c->$action();

      }

  }


}
