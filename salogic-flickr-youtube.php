<?php
/*
Plugin Name: SaLogic Flickr YouTube Integration
Plugin URI: http://salogic.net/
Description: Adds the ability to add YouTube Videos and Flickr sets to your posts in the admin area using checkboxes
Author: Sal Ferrarello
Version: 1.3
Author URI: http://salogic.net/
*/

// debug.php file to add error_logging functionality
// i.e. var_dump to log file
require_once("debug.php");

if (!class_exists ("SaLogicFlickrYouTube")) {
    class SaLogicFlickrYouTube {
        var $flickrApiKey
            , $flickrApiInstance
            , $flickrUserId
            , $flickrAdminSetsPerPage
            , $youTubeUserName;

        public function __construct($args = array() ){
            // default_args are stored as values
            // args passed in as parameters will override these values
            $default_args = array(
                'flickrApiKey'      => self::settingsGetValue('flickrApiKey') // no default value
                // default flickrUserId is 86049135@N00 for sferrarello
                // http://www.flickr.com/photos/86049135@N00/
                , 'flickrUserId'    => self::settingsGetValue('flickrUserId', '86049135@N00') // default value
                , 'youTubeUserName' => self::settingsGetValue('youTubeUserName')
                , 'flickrAdminSetsPerPage'  => self::settingsGetValue('flickrAdminSetsPerPage', 5)
                , 'youTubeAdminSetsPerPage'  => self::settingsGetValue('youTubeAdminSetsPerPage', 5)
            );

            $args = array_merge($default_args, $args);

            // loop throught $args array and store them as properties in the array
            // NOTE: considered using array_map but foreach seems cleaner
            // e.g. $this->flickrApiKey = $args['flickrApiKey'];
            foreach ($args as $key => $value) {
                $this->$key = $value;
            }

            $this->loadAssets();

            add_action('admin_init', array($this, 'addMetaboxes') );
            add_action('save_post', array($this, 'save'), 10, 2 ); // pass 2 parameters (post_id, post)
            add_filter("the_content", array($this, 'content') );

            add_action('admin_menu', array($this, 'addSettingsPage') );
            add_action('admin_init', array($this, 'addSettings') );
        }

        public function addMetaboxes() {
            add_meta_box('salogic_youtube', 'YouTube', array($this, 'youTubeMetabox'), 'post', 'side', 'high');
            add_meta_box('salogic_flickr',  'flickr',  array($this, 'flickrMetabox'),  'post', 'side', 'high');
        }

        public function addSettingsPage() {
            // add_options_page( $page_title, $menu_title, $capability, $menu_slug, $function);
            add_options_page( 'SaLogic flickr/YouTube', 'flickr/YouTube', 'manage_options', 'flickryoutube', array($this, 'settingsPage'));
        }
        public function addSettings() {

            // register_setting( $option_group, $option_name, $sanitize_callback );
            register_setting( 'flickrYouTubeOptions', 'flickrYouTubeOptions', array($this, 'settingsValidation') );

            // SECTION flickr
            // add_settings_section( $id, $title, $callback, $page );
            add_settings_section('flickr_main', 'flickr', array($this, 'settingsSectionFlickrParagraph'), 'flickrYouTubeOptions');

            add_settings_field(
                'flickrApiKey',                     // $id
                'flickr API Key',                   // $title
                array($this, 'settingsTextField'),  // $callback (generic for text field based on $args['key']
                'flickrYouTubeOptions',             // $page
                'flickr_main',                      // $section
                array( 'key'   =>  'flickrApiKey')  // $args['key'] for generic settingsTextField
            );

            add_settings_field(
                'flickrUserId',                     // $id
                'flickr User Id',                   // $title
                array($this, 'settingsTextField'),  // $callback (generic for text field based on $args['key']
                'flickrYouTubeOptions',             // $page
                'flickr_main',                      // $section
                array( 'key'   =>  'flickrUserId')  // $args['key'] for generic settingsTextField
            );

            add_settings_field(
                'flickrAdminSetsPerPage',           // $id
                'flickr Admin Screen Sets per Page',// $title
                array($this, 'settingsTextField'),  // $callback (generic for text field based on $args['key']
                'flickrYouTubeOptions',             // $page
                'flickr_main',                      // $section
                array( 'key'   =>  'flickrAdminSetsPerPage') // $args['key'] for generic settingsTextField
            );

            // SECTION YouTube
            add_settings_section('youtube_main', 'YouTube', array($this, 'settingsSectionYouTubeParagraph'), 'flickrYouTubeOptions');

            add_settings_field(
                'youTubeUserName',                  // $id
                'YouTube User Name',                // $title
                array($this, 'settingsTextField'),  // $callback (generic for text field based on $args['key']
                'flickrYouTubeOptions',             // $page
                'youtube_main',                     // $section
                array( 'key'   =>  'youTubeUserName') // $args['key'] for generic settingsTextField
            );

            add_settings_field(
                'youTubeAdminSetsPerPage',          // $id
                'flickr Admin Screen Sets per Page',// $title
                array($this, 'settingsTextField'),  // $callback (generic for text field based on $args['key']
                'flickrYouTubeOptions',             // $page
                'youtube_main',                     // $section
                array( 'key'   =>  'youTubeAdminSetsPerPage') // $args['key'] for generic settingsTextField
            );
        } // addSettings()

        public function settingsValidation($settingsHash) {
            if (!is_array($settingsHash)) {
                return;
            }
            foreach($settingsHash as $key => $value) {
                $settingsHash[$key] = sanitize_text_field($value);
            }
            return $settingsHash;
        }
        public function settingsSectionFlickrParagraph() {
            echo "Please enter settings specific to your flickr account here.";
        }
        public function settingsSectionYouTubeParagraph() {
            echo "Please enter settings specific to your YouTube account here.";
        }
        // generic call back function to generate a text field for the API Settings
        // $args['key'] is defined for the $id of the field (and the index of the array where the value is stored)
        public function settingsTextField($args) {
            if ( !array_key_exists('key', $args) ) {
                return false;
            }
            $key = $args['key'];
            $value= self::settingsGetValue($key);
            echo "<input id='{$key}' name='flickrYouTubeOptions[{$key}]' size='40' type='text' value='{$value}' />";
        }
        public static function settingsGetValue($key, $default='') {
            $flickrYouTubeOptions = get_option('flickrYouTubeOptions');
            if (
                is_array($flickrYouTubeOptions)
                && array_key_exists($key, $flickrYouTubeOptions)
                && $flickrYouTubeOptions[$key]!==''
            ) {
                return $flickrYouTubeOptions[$key];
            }
            return $default;
        }
        public function settingsPage() {
        ?>
            <div class="wrap">
                <div id="icon-options-general" class="icon32"><br></div><h2>SaLogic flickr / YouTube</h2>

                <form method="post" action="options.php">
                    <?php settings_fields('flickrYouTubeOptions'); ?>
                    <?php do_settings_sections('flickrYouTubeOptions'); ?>
                    <p class="submit"><input type="submit" name="submit" id="submit" class="button button-primary" value="Save Changes"></p></form>
                </form>
            </div><!-- #wrap -->
            <?php
        }



        public function save($post_id, $post) {
            // we have two sets of checkboxes to store, listed in $checkbox_meta_keys
            $checkbox_meta_keys = array('salogic_flickr_list', 'salogic_youtube_list');

            if (
                // abort if this is an auto save routine (form was not submitted)
                ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
                // abort if our nonce is wrong
                || !wp_verify_nonce( $_POST['salogic_flickr_youtube_metabox_nonce'], plugin_basename( __FILE__ ) )
                // abort if current user can not edit this post
                || !current_user_can( 'edit_post', $post_id )
            ) {
                return;
            }

            foreach ($checkbox_meta_keys as $key) {
                $old[$key] = get_post_meta( $post_id, $key, true );
                // only modify value stored
                // if the data for this metabox appears in the $_POST array
                if ( array_key_exists($key, $_POST) ) {
                    $new[$key] = $_POST[$key];
                    $new[$key] = ($new[$key] ? implode( ',', $new[$key] ) : '');

                    if ( $new[$key] && $new[$key] != $old[$key] ) {
                        update_post_meta($post_id, $key, $new[$key]);
                    } elseif ( '' == $new[$key] && $old[$key] ) {
                        delete_post_meta($post_id, $key, $old[$key]);
                    }
                }
            } // foreach meta_key
        } // save()

        public function flickrMetabox() {
            global $post;

            // NOTE: this nonce field is used for both the flickr and youtube parts of the this plugin
            wp_nonce_field( plugin_basename( __FILE__ ), 'salogic_flickr_youtube_metabox_nonce' );

            // load list of selected photosets
            $selectedPhotosets =  explode(',', get_post_meta($post->ID, 'salogic_flickr_list', true) );

            // load n most recent photosets
            // currently unused parameters
            $page = 1;
            $perPage = $this->flickrAdminSetsPerPage;
            // NOTE: flickr api 3.1 photosets_getList does NOT support $page and $perPage parameters on api call
            // therefore we load them all and restrict display via js
            // leaving these variables in place in preparation for future iterations

            // api call
            $recentPhotosets = $this->getFlickrPhotosets($page, $perPage);
            $recentPhotosets = ( $recentPhotosets ? $recentPhotosets : array() );

            // display union of selected photosets && n most recent photosets (hide others)
            echo '<ul id="salogic-flickr-photosets">';
                foreach ($recentPhotosets as $i => $photoset) {
                    // note: hiding photosets beyond the first n via css is a hack
                    // until the code making the api call is upgraded to support pagination
                    // at that time we'll only load the first n and load any additional via an ajax call

                    $checked = in_array($photoset['id'], $selectedPhotosets);
                    // initially hide anything beyond the first "page" that is NOT checked
                    $hidden = ($i>=$perPage) && !$checked;
                    $this->flickrAdminPhotosetTemplate( $photoset, $checked, $hidden );
                } // foreach $photoset
            echo '</ul>';
            echo '<span id="salogic-flickr-photosets-load-more" class="load-more button">Load More flickr Photosets</a>';
        } // flickrMetabox()

        public function youTubeMetabox() {
            global $post;
            $selectedYouTubeVideos =  explode(',', get_post_meta($post->ID, 'salogic_youtube_list', true) );
            echo '<ul id="salogic-youtube-videos">';

            foreach ($selectedYouTubeVideos as $i => $video) {
                if ($video) {
                    $this->youTubeAdminVideoTemplate($video);
                }
            } // foreach video

            echo '</ul>';
            echo '<span id="salogic-youtube-videos-load-more" class="load-more button">Load More YouTube Videos</a>';
        } // youTubeMetabox()

        public function loadAssets() {
            // php files
            require_once("phpFlickr-3.1/phpFlickr.php");

            // css
            add_action( 'admin_enqueue_scripts', array($this, 'loadCss'), 100 );

            // js
            add_action( 'admin_enqueue_scripts', array($this, 'loadJavaScript'), 100 );

            // load global javascript variables
            add_action( 'admin_footer', array($this, 'loadJavaScriptGlobalVariables') );

            // load front end resources
            add_action( 'wp_enqueue_scripts', array($this, 'loadFrontEndAssets') );
        }

        public function loadJavaScript($hook) {
            // load javascript in footer
            wp_enqueue_script( 'salogicFlickrYouTube', plugin_dir_url( __FILE__ ).'/js/salogic-flickr-youtube-admin.min.js', array('jquery'), '1.1', true );
        }
        public function loadJavaScriptGlobalVariables() {
            ?>
            <script>
                var youTubeUserName = '<?php echo $this->youTubeUserName; ?>'
                    ,flickrAdminSetsPerPage = <?php echo $this->flickrAdminSetsPerPage; ?>
                    , youTubeAdminSetsPerPage = <?php echo $this->youTubeAdminSetsPerPage; ?>;
            </script>
            <?php
        }

        public function loadFrontEndAssets() {
            wp_enqueue_style('salogicFlickrYouTubeFrontEnd', plugin_dir_url( __FILE__ ).'css/salogic-flickr-youtube.css', false, '1.3');

            wp_enqueue_script('colorbox', plugin_dir_url( __FILE__ ).'js/jquery.colorbox-min.js', array('jquery'), '1.4.31', true);
            wp_enqueue_script('salogicFlickrYouTubeFrontEnd', plugin_dir_url( __FILE__ ).'js/salogic-flickr-youtube.js', array('jquery','colorbox'), '1.3', true);
        }

        public function loadCss($hook) {
            // load css
            wp_enqueue_style( 'salogicFlickrYouTube', plugin_dir_url( __FILE__ ).'/css/salogic-flickr-youtube-admin.css', array(), '1.1', 'all');
        }

        public function content($content) {
            // TODO: improve the front-end
            //      - remove extraneous markup and use css3 for styling
            global $post;
            $flickr_gallery = '';
            // BEGIN: build flickr gallery

            $salogic_flickr_list =  explode(',', get_post_meta($post->ID, 'salogic_flickr_list', true) );
            if ( $salogic_flickr_list[0] ) {
                $f = $this->getFlickrApiInstance();


                $flickr_gallery .= "<ul class='clearfix photos thumbs'>";
                foreach ($salogic_flickr_list as $photoset_id) {
                    $photos = $f->photosets_getPhotos($photoset_id, "media");
                    if ($photos) {
                        foreach ($photos['photoset']['photo'] as $photo) {
                            $flickr_gallery .= "<li>";
                                $flickr_gallery .= "<a href='";
                                $flickr_gallery .= "http://farm".$photo['farm'].".static.flickr.com/".$photo['server']."/".$photo['id']."_".$photo['secret'].".jpg'";
                                $flickr_gallery .= " title='".htmlentities($photo['title'], ENT_QUOTES)."'";  // replace html entities including " and '
                                $flickr_gallery .= " rel='group_".$post->ID."'";
                                $flickr_gallery .= " class='trigger ccframe'>";
                                  $flickr_gallery .= "<img src=";
                                  $flickr_gallery .= "'http://farm".$photo['farm'].".static.flickr.com/".$photo['server']."/".$photo['id']."_".$photo['secret']."_t.jpg'";
                                  $flickr_gallery .= " alt='".htmlentities($photo['title'], ENT_QUOTES)."' />";
                                $flickr_gallery .= "</a><!-- .frame -->\n";
                                $flickr_gallery .= "<div class='tooltip'>\n";
                                $flickr_gallery .= "<span class='top'><!-- .top --></span>\n";
                                $flickr_gallery .= "<div class='middle'>\n";
                                $flickr_gallery .= "<h4>".htmlentities($photo['title'], ENT_QUOTES)."</h4>";
                                $flickr_gallery .= "click the photo below to enlarge<br />";
                                $flickr_gallery .= "or view the <a rel='external' href=";
                                $flickr_gallery .= "'http://farm".$photo['farm'].".static.flickr.com/".$photo['server']."/".$photo['id']."_".$photo['secret']."_b.jpg'";
                                $flickr_gallery .= ">";
                                $flickr_gallery .= "printable version";
                                $flickr_gallery .= "</a>";
                                $flickr_gallery .= "</div><!-- .middle -->\n";
                                $flickr_gallery .= "<span class='bottom'><!-- .bottom --></span>\n";
                                $flickr_gallery .= "</div><!-- .tooltip -->";

                            $flickr_gallery .= "</li>";
                        } // foreach $photo
                    } // if $photos are defined
                } // foreach photoset
                $flickr_gallery .= "</ul>";
            } else {
                $flickr_gallery = '';
            }
            // END: build flickr gallery

            // BEGIN: build youtube gallery
            $salogic_youtube_list =  explode(',', get_post_meta($post->ID, 'salogic_youtube_list', true) );
            if ( $salogic_youtube_list[0] ) {

                $youtube_gallery = "<ul class='clearfix photos thumbs'>";
                foreach ($salogic_youtube_list as $video_id) {
                        $youtube_gallery .= "<li>";
                            $youtube_gallery .= "<a href='";
                            $youtube_gallery .= "http://www.youtube.com/watch?v=".$video_id."'";
                            $youtube_gallery .= " rel='group_".$post->ID."'";
                            $youtube_gallery .= " class='youtubetrigger ccframe'>";
                              $youtube_gallery .= "<img src=";
                              $youtube_gallery .= "'http://i3.ytimg.com/vi/".$video_id."/default.jpg'";
                              $youtube_gallery .= " alt='"."alt text"."' />";
                                $youtube_gallery .= "<span class='videoicon'><!-- .videoicon --></span>\n";
                            $youtube_gallery .= "</a><!-- .frame -->\n";
                            $youtube_gallery .= "<div class='tooltip'>\n";
                            $youtube_gallery .= "<span class='top'><!-- .top --></span>\n";
                            $youtube_gallery .= "<div class='middle'>\n";
                            $youtube_gallery .= "<h4>"."Video"."</h4>";
                            $youtube_gallery .= "click the photo below to view<br />the video";
                            $youtube_gallery .= "</div><!-- .middle -->\n";
                            $youtube_gallery .= "<span class='bottom'><!-- .bottom --></span>\n";
                            $youtube_gallery .= "</div><!-- .tooltip -->";
                        $youtube_gallery .= "</li>";
                } // foreach video
                $youtube_gallery .= "</ul>";
            } else {
                $youtube_gallery = '';
            }
            return $content . '<div class="salogicphotoset">'. $flickr_gallery . $youtube_gallery. '</div><!-- .salogicphotoset -->';
        } // content()


        // Returns result of http://www.flickr.com/services/api/flickr.photosets.getList.html
        private function getFlickrPhotosets($page, $perPage) {
            $f = $this->getFlickrApiInstance();

            // NOTE: flickr api 3.1 photosets_getList does NOT support $page and $perPage parameters on api call
            // therefore we load them all and restrict display via js
            $result = $f->photosets_getList($this->flickrUserId);  # get photosets for sferrarello (aka 86049135@N00)
            return $result['photoset'];

        } // getFlickrPhotosets()

        private function getFlickrApiInstance() {
            if (!$this->flickrApiInstance) {
                $this->flickrApiInstance = new phpFlickr($this->flickrApiKey);
            }
            return $this->flickrApiInstance;
        } // getFlickrApiInstance

        // flickrAdminPhotosetTemplate()
        // takes two parameters
        //      $photoset - result returned by http://www.flickr.com/services/api/flickr.photosets.getList.html
        //      $checked - bool, is this item selected
        //      $hidden - bool, is this item hidden
        private function flickrAdminPhotosetTemplate($photoset, $checked=false, $hidden=false) {
            ?>

            <li class="<?php echo ($hidden ? 'hidden' : ''); ?>">
                <label class="selectit salogic-image-preview-label">
                    <input <?php echo ($checked ? 'checked="checked"' : ''); ?> name="salogic_flickr_list[]" type="checkbox" value="<?php echo $photoset['id']; ?>" />
                    <?php echo $photoset['title']; ?>
                    <img src="http://farm<?php echo $photoset['farm'].'.static.flickr.com/'.$photoset['server'].'/'.$photoset['primary'].'_'.$photoset['secret'].'_t.jpg'; ?>" alt="<?php echo '$photoset'; ?>" />
                </label>
            </li>
            <?php
        } // flickrAdminPhotosetTemplate

        private function youTubeAdminVideoTemplate($video) {
            ?>
            <li data-youtube-id="<?php echo $video; ?>">
                 <label class="selectit salogic-image-preview-label">
                    <input checked="checked" name="salogic_youtube_list[]" type="checkbox" value="<?php echo $video; ?>" />
                    <span class="title">TBA</span>
                    <img src="http://img.youtube.com/vi/<?php echo $video; ?>/default.jpg" alt="<?php echo $video; ?>" />
                </label>
            </li>
            <?php
        } // youTubeAdminVideoTemplate()

    } // class SaLogicFlickrYouTube
} else {
    error_log('class SaLogicFlickrYouTube already loaded but attempted to load a second time');
}

new SaLogicFlickrYouTube();

?>
