<?php
/*
 Plugin Name: GW BP Profile Import / Export
 Plugin URI: 
 Description: Allows users to Import BP data from sister wwoof sites. Both must be running same plugin. [gw-profile-importer]
 Author: GippslandWeb
 Version: 1.3
 Author URI: http://gippslandweb.com.au
 GitHub Plugin URI: gippslandweb/gw-bp-profile-importer
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

     function AjaxImport() {
         $user = $_POST['u'];
         $pass = $_POST['p'];
         $url = "http://www.lakes.com.au";
         $url = "http://localhost";
         if($_POST['s'] == 'coastal')
            $url = "http://coastalwaters.info";
         global $wpdb;
         $response = wp_remote_post($url."/wp-json/gwnb/v1/export", array('method' => 'POST', 'body' => array('u' => $user, 'p' => $pass, 's' => $this->sharedKey)));
         if(is_wp_error($response)) {
             echo "Something went wrong";
         }
         else {
             $data = json_decode($response['body']);
             if($data->result == false)
             {
                 echo 'failure';
                 return;
             }
             var_dump($data->reviews);
             die();
             foreach($data->data as $d) {
                 //check its type against type
		$t = $wpdb->get_row('SELECT type from wp_bp_xprofile_fields WHERE id = '.$d->field_id);
                 if($t &&  $t->type != $d->type){
                     echo "|| type mismatch skipping field: ".$d->field_id;
                     continue;
                 }
                 $x = $wpdb->get_row('SELECT * from wp_bp_xprofile_data where user_id ='.get_current_user_id(). 'AND field_id = '.$d->field_id);
                 if($x == null){
 $r = $wpdb->update(
                     'wp_bp_xprofile_data',
                     array('value' => $d->value, 'last_updated' => current_time('mysql')),
                     array('field_id'=> $d->field_id, 'user_id' => get_current_user_id()),
                     array('%s','%s'),
                     array('%d','%d'));
echo "||updated field".$d->field_id." ".$d->value;
                 }
                 else {
$wpdb->insert('wp_bp_xprofile_data', array('field_id'=> $d->field_id, 'user_id' => get_current_user_id(), 'value' => $d->value, 'last_updated' => current_time('mysql')));
echo "||Inserted field".$d->field_id." ".$d->value;       
                 }
             }
//store the reviews.
if(isset($data->reviews)) {
    foreach($data->reviews as $rev) {
        add_user_meta(get_current_user_id(),'imported-review',$rev);
    }
}

         }
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
ON (wp_bp_xprofile_data.field_id = wp_bp_xprofile_fields.id) WHERE user_id = ''";

//             $sql = "SELECT * FROM wp_bp_xprofile_data WHERE user_id = '$user->ID'";
             $res = $wpdb->get_results($sql);
             
             $results['data'] = $res;

$reviewsQuery = array(
      'post_type' =>'bp-user-reviews',
      'post_status' =>'publish',
      'posts_per_page' => -1,
      'meta_query' => array(array('key' => 'user_id','value'=> $user->ID)));

             $results['reviews'] = get_posts($reviewsQuery);
             foreach($results['reviews'] as $rev) {
                $rev->author_name = bp_core_get_username($rev->post_author);
                $rev->backlink = get_site_url().'/members/'.$rev->author_name;
             }
         }
         else {
            
         }
         //check credentials
         //dump user to JSON 
         return $results;
         
     }






     function RenderImporter() {
         	require_once(dirname(__FILE__) . "/importer-template.html");
     }

 }

 $gwprofileImporter = new GW_ProfileImporter();