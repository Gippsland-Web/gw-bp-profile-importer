<?php
/*
 Plugin Name: GW BP Profile Import / Export
 Plugin URI: 
 Description: Allows users to Import BP data from sister wwoof sites. Both must be running same plugin. [gw-profile-importer]
 Author: GippslandWeb
 Version: 1.5.5
 Author URI: http://gippslandweb.com.au
 GitHub Plugin URI: Gippsland-Web/gw-bp-profile-importer
 */

 class GW_ProfileImporter {
     private      $sharedKey = 'xjd7DlQLPCc5nxjd7Dl';

     public function __construct() {
         add_action('rest_api_init',function() {
            register_rest_route('gwnb/v1','/export',array('methods'=>'POST','callback' => array($this,'ExportUser'),
	                            'permission_callback' => function () {
					                return true;
	                            }
	            ));
        });
        
        add_action('wp_ajax_gw_import_user', 	 array($this, 'AjaxImport'));
        add_shortcode("gw-profile-importer",array($this,'RenderImporter'));

     
     }

     function AjaxImport() 
     {
         $result = new \stdClass();
         $result->result = true;
         $user = $_POST['u'];
         $pass = $_POST['p'];
         $url = "http://www.lakes.com.au";
         //$url = "http://localhost";
         if($_POST['s'] == 'coastal')
            $url = "http://coastalwaters.info";
         global $wpdb;
         $response = wp_remote_post($url."/wp-json/gwnb/v1/export", array('method' => 'POST', 'body' => array('u' => $user, 'p' => $pass, 's' => $this->sharedKey)));
         if(is_wp_error($response)) {
             $result->result = false;
             array_push($result->errors,"Error connecting to remote site");
             return json_encode($result);
         }
        $data = json_decode($response['body']);
        if($data->result == false)
        {
        $result->result = false;
        array_push($result->errors,"Error parsing data from remote site.");
        return json_encode($result);
        }
        //var_dump($data);
        foreach($data->data as $d) {
            //check its type against type
            $t = $wpdb->get_row('SELECT type from wp_bp_xprofile_fields WHERE id = '.$d->field_id);
            if($t &&  $t->type != $d->type){
                echo "|| type mismatch skipping field: ".$d->field_id;
                continue;
            }
            $x = $wpdb->get_row('SELECT * from wp_bp_xprofile_data where user_id ='.get_current_user_id(). 'AND field_id = '.$d->field_id);
            //var_dump($x);
            if($x != null){
                $r = $wpdb->update(
                'wp_bp_xprofile_data',
                array('value' => $d->value, 'last_updated' => current_time('mysql')),
                array('field_id'=> $d->field_id, 'user_id' => get_current_user_id()),
                array('%s','%s'),
                array('%d','%d'));
            }
            else {
$wpdb->insert('wp_bp_xprofile_data', array('field_id'=> $d->field_id, 'user_id' => get_current_user_id(), 'value' => $d->value, 'last_updated' => current_time('mysql')));
            }
        }

//store the reviews.
    if(isset($data->reviews)) {
        foreach($data->reviews as $rev) {
            add_user_meta(get_current_user_id(),'imported-review',$rev);
        }
    }

    //Delete existing cover image
    bp_attachments_delete_file( array( 'item_id' => get_current_user_id(), 'object_dir' => "members", 'type' => 'cover-image' ) );


    //Download and store new cover image
    $get = wp_remote_get($data->cover);
    $localCopy = wp_upload_bits('x'.basename($data->cover),'',wp_remote_retrieve_body($get));
    $cover_subdir = 'members' . '/' . get_current_user_id() . '/cover-image';
    $cover_dir    = trailingslashit( bp_attachments_uploads_dir_get()['basedir'] ) . $cover_subdir;
    if ( ! file_exists( trailingslashit($cover_dir) ) ) {
        wp_mkdir_p(trailingslashit($cover_dir));
    }
    rename($localCopy['file'],trailingslashit($cover_dir).basename($data->cover));



    //Import avatar and header photo
    $image = $data->avatar;
    $get = wp_remote_get($data->avatar);

    $mirror = wp_upload_bits('x'.basename($data->avatar),'',wp_remote_retrieve_body($get));

    $avatar_to_crop = $mirror['url'];
    $avatar_to_crop = str_replace(get_site_url().'/wp-content/uploads','',$avatar_to_crop);

    // Crop to default values.
    $crop_args = array( 'item_id' => get_current_user_id(), 'original_file' => $avatar_to_crop, 'crop_x' => 0, 'crop_y' => 0,'avatar_dir' => 'avatars','object' => 'user' );

    $avatar_folder_dir = bp_core_avatar_upload_path() . '/avatars/'.get_current_user_id().'/';
    if ( ! file_exists( $avatar_folder_dir ) ) {
        wp_mkdir_p($avatar_folder_dir);
    }
    $avatar_attachment = new BP_Attachment_Avatar();
    $cropped           = $avatar_attachment->crop( $crop_args );
    unlink(basename($mirror['file']));
    do_action( 'xprofile_avatar_uploaded', get_current_user_id(), "Upload" );

    
}


     function ExportUser() {
         global $wpdb;
         $results = array();
         $results['result'] = false;
         $u = $_POST['u'];
         $p = $_POST['p'];
         if($_POST['s'] != $this->sharedKey){
            $results['msg'] ='Only WWOOF to WWOOF transfers allowed.';
         }
            
         $user = wp_authenticate($u,$p);
         if($user) {
             $results['result'] = true;
             $sql = "SELECT `field_id`, `user_id`, `value`, `last_updated`, `type` FROM wp_bp_xprofile_data INNER JOIN wp_bp_xprofile_fields 
ON (wp_bp_xprofile_data.field_id = wp_bp_xprofile_fields.id) WHERE user_id = ".$user->ID;

//             $sql = "SELECT * FROM wp_bp_xprofile_data WHERE user_id = '$user->ID'";
             $res = $wpdb->get_results($sql);
             
             $results['data'] = $res;
             $results['reviews'] = array();
             $results['avatar'] = bp_core_fetch_avatar(array('item_id' => $user->ID, 'type' => 'full', 'html' => false ));
$reviewsQuery = array(
      'post_type' =>'bp-user-reviews',
      'post_status' =>'publish',
      'posts_per_page' => -1,
      'meta_query' => array(array('key' => 'user_id','value'=> $user->ID)));

      $results['cover'] = bp_attachments_get_attachment('url',array("object_dir" => "members", 'item_id' => $user->ID, 'type' => 'cover-image'));


             foreach(get_posts($reviewsQuery) as $r) {
                 $rev = new \stdClass();

                $rev->author_name = bp_core_get_username($r->post_author);
                $rev->backlink = get_site_url().'/members/'.$r->author_name;
                $rev->review = $r->review;
                $rev->stars = $r->stars;
                array_push($results['reviews'],$rev);
             }
         }
         else {
            
         }
         return $results;
         
     }






     function RenderImporter() {
         	require_once(dirname(__FILE__) . "/importer-template.html");
     }

 }

 $gwprofileImporter = new GW_ProfileImporter();