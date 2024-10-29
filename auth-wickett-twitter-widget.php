<?php
/*
Plugin Name: Authenticated Twitter Widget 
Description: Based off of Automattic's Wickett Twitter widget. Display the <a href="http://twitter.com/">Twitter</a> latest updates from a Twitter user inside your theme's widgets with the option of using Authenticated API calls. Customize the number of displayed Tweets, filter out replies, and include retweets.<br/><em>*Can set a cache expire time (in seconds) to stay under Twitter's rate limit for authorized API calls.</em>
Author: Brian Cerceo
Author URI: http://www.black-tooth.com
Version: 1.0
License: GPLv2
*/

/*
This program is free software; you can redistribute it and/or
modify it under the terms of the GNU General Public License
as published by the Free Software Foundation; either version 2
of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
*/


/* TWITTER Account OAuth Settings (https://dev.twitter.com/apps/) */

// inits json decoder/encoder object if not already available
global $wp_version;
if(!class_exists('TwitterOAuth')){
    include_once( dirname( __FILE__ ) . '/twitteroauth.php' );
    include_once( dirname( __FILE__ ) . '/OAuth.php' );
}
if ( version_compare( $wp_version, '2.9', '<' ) && !class_exists( 'Services_JSON' ) ) {
    include_once( dirname( __FILE__ ) . '/class.json.php' );
}

if ( !function_exists('wpcom_time_since') ) :
/*
 * Time since function taken from WordPress.com
 */

function wpcom_time_since( $original, $do_more = 0 ) {
        // array of time period chunks
        $chunks = array(
                array(60 * 60 * 24 * 365 , 'year'),
                array(60 * 60 * 24 * 30 , 'month'),
                array(60 * 60 * 24 * 7, 'week'),
                array(60 * 60 * 24 , 'day'),
                array(60 * 60 , 'hour'),
                array(60 , 'minute'),
        );

        $today = time();
        $since = $today - $original;

        for ($i = 0, $j = count($chunks); $i < $j; $i++) {
                $seconds = $chunks[$i][0];
                $name = $chunks[$i][1];

                if (($count = floor($since / $seconds)) != 0)
                        break;
        }

        $print = ($count == 1) ? '1 '.$name : "$count {$name}s";

        if ($i + 1 < $j) {
                $seconds2 = $chunks[$i + 1][0];
                $name2 = $chunks[$i + 1][1];

                // add second item if it's greater than 0
                if ( (($count2 = floor(($since - ($seconds * $count)) / $seconds2)) != 0) && $do_more )
                        $print .= ($count2 == 1) ? ', 1 '.$name2 : ", $count2 {$name2}s";
        }
        return $print;
}
endif;

if ( !function_exists('http_build_query') ) :
    function http_build_query( $query_data, $numeric_prefix='', $arg_separator='&' ) {
       $arr = array();
       foreach ( $query_data as $key => $val )
         $arr[] = urlencode($numeric_prefix.$key) . '=' . urlencode($val);
       return implode($arr, $arg_separator);
    }
endif;

class Auth_Twitter_Widget extends WP_Widget {
    function Auth_Twitter_Widget() {
        $widget_ops = array('classname' => 'widget_twitter', 'description' => __( 'Display your tweets from Twitter') );
        parent::WP_Widget('twitter', __('Twitter'), $widget_ops);
    }
    function getConnectionWithAccessToken($consumer_key, $consumer_secret, $oauth_token, $oauth_token_secret) {
      $connection = new TwitterOAuth($consumer_key, $consumer_secret, $oauth_token, $oauth_token_secret);
      return $connection;
    }

    function widget( $args, $instance ) {
        extract( $args );

        $account = trim( urlencode( $instance['account'] ) );
        if ( empty($account) ) return;
        $title = apply_filters('widget_title', $instance['title']);
        if ( empty($title) ) $title = __( 'Twitter Updates' );
        $show = absint( $instance['show'] );  // # of Updates to show
        if ( $show > 200 ) // Twitter paginates at 200 max tweets. update() should not have accepted greater than 20
            $show = 200;
        $hidereplies = (bool) $instance['hidereplies'];
        $include_retweets = (bool) $instance['includeretweets'];
        $useAuth = (bool) $instance['useAuth'];
        $consumer_key = $instance['consumer_key'];
        $consumer_secret = $instance['consumer_secret'];
        $oauth_token = $instance['oauth_token'];
        $oauth_token_secret = $instance['oauth_token_secret'];
        $expire_time = $instance['expire_time'];

        echo "{$before_widget}{$before_title}<a href='" . esc_url( "http://twitter.com/{$account}" ) . "'>" . esc_html($title) . "</a>{$after_title}";

        // TWITTER RATE LIMIT: get twitter rate limit for unauthorized calls
        if(!$useAuth){
            $tweet_rate_limit = '';
            $twitter_rate_limit_url = 'http://api.twitter.com/1/account/rate_limit_status.json';
            $response = wp_remote_get( $twitter_rate_limit_url, array( 'User-Agent' => 'WordPress.com Twitter Widget' ) );
            $response_code = wp_remote_retrieve_response_code( $response );
            if ( 200 == $response_code ) {
                $tweet_rate_limit = wp_remote_retrieve_body( $response );
                $tweet_rate_limit = json_decode( $tweet_rate_limit, true );
                if ( !is_array( $tweet_rate_limit ) || isset( $tweet_rate_limit['error'] ) ) {
                    $tweet_rate_limit = 'error';
                }
            } else {
                $tweet_rate_limit = 'error';
            }
        }
        // END TWITTER RATE LIMIT
        
        // Get any existing copy of our transient data from the db
        if ( false === ( $tweets = get_transient( 'twitter_results' ) ) ) {
            // if no cached results in db then fetch the latest
            
            if($useAuth){
                /* OAuth Calls */
                /* statuses/public_timeline */
                $connection = $this->getConnectionWithAccessToken($consumer_key, $consumer_secret, $oauth_token, $oauth_token_secret);
                $tweets = $connection->get("statuses/user_timeline");
    
                $tweet_rate_limit['remaining_hits'] = $connection->http_header['x_ratelimit_remaining'];
                $tweet_rate_limit['hourly_limit'] = $connection->http_header['x_ratelimit_limit'];
                $tweet_rate_limit['expire_time'] = $expire_time;
                
                
                $params = array(
                    'screen_name'=>$account, // Twitter account name
                    'trim_user'=>true, // only basic user data (slims the result)
                    'include_entities'=>false // as of Sept 2010 entities were not included in all applicable Tweets. regex still better
                );
                
                set_transient( 'twitter_results', $tweets, $expire_time );
                set_transient( 'twitter_rate_results', $tweet_rate_limit, $expire_time );
            }else{
                /**
                 * The exclude_replies parameter filters out replies on the server. If combined with count it only filters that number of tweets (not all tweets up to the requested count)
                 * If we are not filtering out replies then we should specify our requested tweet count
                 */
                if ( $hidereplies )
                    $params['exclude_replies'] = true;
                else
                    $params['count'] = $show;
                if ( $include_retweets )
                    $params['include_rts'] = true;
                $twitter_json_url = esc_url_raw( 'http://api.twitter.com/1/statuses/user_timeline.json?' . http_build_query($params), array('http', 'https') );
                unset($params);
                
                $response = wp_remote_get( $twitter_json_url, array( 'User-Agent' => 'WordPress.com Twitter Widget' ) );
                $response_code = wp_remote_retrieve_response_code( $response );
                if ( 200 == $response_code ) {
                    // It transient was not there, regenerate the data and save the transient
                    $tweets = wp_remote_retrieve_body( $response );
                    $tweets = json_decode( $tweets, true );
                    if ( !is_array( $tweets ) || isset( $tweets['error'] ) ) {
                        $tweets = 'error';
                    }
                } else {
                    $tweets = 'error';
                    set_transient( 'twitter-response-code', $response_code, $expire_time);
                }
                set_transient( 'twitter_results', $tweets, $expire_time );
                set_transient( 'twitter_rate_results', "$tweet_rate_limit[remaining_hits]/$tweet_rate_limit[hourly_limit] ($expire_time sec)", $expire_time );
            }
        }else{
            $isCached = "(cached)";
            $tweet_rate_limit = get_transient( 'twitter_rate_results' );
        }
        $cacheResult = (isset($isCached))? 'true':'false';
        
        // debugging rate limit calls
        //echo "<div><b>twitter</b> isCached: $cacheResult, remaining api calls: $tweet_rate_limit[remaining_hits]/$tweet_rate_limit[hourly_limit]</div>";

        if ( 'error' != $tweets ) :
            $before_timesince = ' ';
            if ( isset( $instance['beforetimesince'] ) && !empty( $instance['beforetimesince'] ) )
                $before_timesince = esc_html($instance['beforetimesince']);
            $before_tweet = '';
            if ( isset( $instance['beforetweet'] ) && !empty( $instance['beforetweet'] ) )
                $before_tweet = stripslashes(wp_filter_post_kses($instance['beforetweet']));
            
            $rate_results = get_transient( 'twitter_rate_results');
            echo "<ul class='tweets' iscached='$cacheResult' ratelimit='$tweet_rate_limit[remaining_hits]/$tweet_rate_limit[hourly_limit]' >" . "\n";

            $tweets_out = 0;
            if($useAuth){
                foreach ( (array) $tweets as $tweet ) {
                    if ( $tweets_out >= $show )
                        break;
                    if ( empty( $tweet->text ) )
                        continue;
                    
                    // if you dont want to include retweets
                    if ( !$include_retweets ){
                        if($tweet->retweeted){
                            continue;
                        }
                    }
                    // hide replies
                    if ( $hidereplies ){
                        if(!($tweet->in_reply_to_user_id=="" && $tweet->in_reply_to_status_id_str=="" && $tweet->in_reply_to_status_id=="" && $tweet->in_reply_to_user_id_str=="")){
                            continue;
                        }
                    }
                    $text = make_clickable( esc_html( $tweet->text ) );
                    /*
                     * Create links from plain text based on Twitter patterns
                     * @link http://github.com/mzsanford/twitter-text-rb/blob/master/lib/regex.rb Official Twitter regex
                     */
                    $text = preg_replace_callback('/(^|[^0-9A-Z&\/]+)(#|\xef\xbc\x83)([0-9A-Z_]*[A-Z_]+[a-z0-9_\xc0-\xd6\xd8-\xf6\xf8\xff]*)/iu',  array($this, '_wpcom_widget_twitter_hashtag'), $text);
                    $text = preg_replace_callback('/([^a-zA-Z0-9_]|^)([@\xef\xbc\xa0]+)([a-zA-Z0-9_]{1,20})(\/[a-zA-Z][a-zA-Z0-9\x80-\xff-]{0,79})?/u', array($this, '_wpcom_widget_twitter_username'), $text);
                    if ( isset($tweet->id_str) )
                        $tweet_id = urlencode($tweet->id_str);
                    else
                        $tweet_id = urlencode($tweet->id);
                    echo "<li>{$before_tweet}{$text}{$before_timesince}<a href=\"" . esc_url( "http://twitter.com/{$account}/statuses/{$tweet_id}" ) . '" class="timesince">' . str_replace(' ', '&nbsp;', wpcom_time_since(strtotime($tweet->created_at))) . "&nbsp;ago</a></li>\n";
                    unset($tweet_id);
                    $tweets_out++;
                }
            }else{
                // forunauthorized calls
                foreach ( (array) $tweets as $tweet ) {
                    print "tweet: ".html_entity_decode( $tweet->text);
                    if ( $tweets_out >= $show )
                        break;
    
                    if ( empty( $tweet['text'] ) )
                        continue;
                    
                    $text = make_clickable( esc_html( $tweet['text'] ) );
                
                    // Create links from plain text based on Twitter patterns
                    // link http://github.com/mzsanford/twitter-text-rb/blob/master/lib/regex.rb Official Twitter regex
                    $text = preg_replace_callback('/(^|[^0-9A-Z&\/]+)(#|\xef\xbc\x83)([0-9A-Z_]*[A-Z_]+[a-z0-9_\xc0-\xd6\xd8-\xf6\xf8\xff]*)/iu',  array($this, '_wpcom_widget_twitter_hashtag'), $text);
                    $text = preg_replace_callback('/([^a-zA-Z0-9_]|^)([@\xef\xbc\xa0]+)([a-zA-Z0-9_]{1,20})(\/[a-zA-Z][a-zA-Z0-9\x80-\xff-]{0,79})?/u', array($this, '_wpcom_widget_twitter_username'), $text);
                    if ( isset($tweet['id_str']) )
                        $tweet_id = urlencode($tweet['id_str']);
                    else
                        $tweet_id = urlencode($tweet['id']);
                    echo "<li>{$before_tweet}{$text}{$before_timesince}<a href=\"" . esc_url( "http://twitter.com/{$account}/statuses/{$tweet_id}" ) . '" class="timesince">' . str_replace(' ', '&nbsp;', wpcom_time_since(strtotime($tweet['created_at']))) . "&nbsp;ago</a></li>\n";
                    unset($tweet_id);
                    $tweets_out++;
                }
            }
            echo "</ul>\n";
        else :
            $rate_results = get_transient( 'twitter_rate_results', "$tweet_rate_limit[remaining_hits]/$tweet_rate_limit[hourly_limit]", $expire_time );
            if ( 401 == get_transient( 'twitter-response-code') )
                echo "<p ratelimit='$rate_results'>" . ( sprintf( __( 'Error: Please make sure the Twitter account is <a href="%s">public</a>.'), 'http://support.twitter.com/forums/10711/entries/14016' ) ) . '</p>';
            else
                echo "<p ratelimit='$rate_results'>" . esc_html__('Error: Twitter did not respond. Please wait a few minutes and refresh this page.') . '</p>';
        endif;

        echo $after_widget;
    }

    function update( $new_instance, $old_instance ) {
        $instance = $old_instance;

        $instance['account'] = trim( strip_tags( stripslashes( $new_instance['account'] ) ) );
        $instance['account'] = str_replace('http://twitter.com/', '', $instance['account']);
        $instance['account'] = str_replace('/', '', $instance['account']);
        $instance['account'] = str_replace('@', '', $instance['account']);
        $instance['account'] = str_replace('#!', '', $instance['account']); // account for the Ajax URI
        $instance['title'] = strip_tags(stripslashes($new_instance['title']));
        $instance['show'] = absint($new_instance['show']);
        $instance['hidereplies'] = isset($new_instance['hidereplies']);
        $instance['includeretweets'] = isset($new_instance['includeretweets']);
        $instance['beforetimesince'] = $new_instance['beforetimesince'];
        if((bool) $instance['useAuth'] != isset($new_instance['useAuth'])){
            delete_transient( 'twitter_results' );
            delete_transient( 'twitter_rate_results' );
        }
        $instance['useAuth'] = isset($new_instance['useAuth']);
        
        $instance['consumer_key'] = ($new_instance['consumer_key']);
        $instance['consumer_secret'] = ($new_instance['consumer_secret']);
        $instance['oauth_token'] = ($new_instance['oauth_token']);
        $instance['oauth_token_secret'] = ($new_instance['oauth_token_secret']);
        $instance['expire_time'] = ($new_instance['expire_time']);
        return $instance;
    }

    function form( $instance ) {
        //Defaults
        $instance = wp_parse_args( (array) $instance, array('account' => '', 'title' => '', 'show' => 5, 'hidereplies' => false, 'expire_time' => 300) );

        $account = esc_attr($instance['account']);
        $title = esc_attr($instance['title']);
        $show = absint($instance['show']);
        if ( $show < 1 || 20 < $show )
            $show = 5;
        $hidereplies = (bool) $instance['hidereplies'];
        $include_retweets = (bool) $instance['includeretweets'];
        $before_timesince = esc_attr($instance['beforetimesince']);
        $useAuth = (bool) $instance['useAuth'];
        $consumer_key = esc_attr($instance['consumer_key']);
        $consumer_secret = esc_attr($instance['consumer_secret']);
        $oauth_token = esc_attr($instance['oauth_token']);
        $oauth_token_secret = esc_attr($instance['oauth_token_secret']);
        $expire_time = esc_attr($instance['expire_time']);

        echo '<p><label for="' . $this->get_field_id('title') . '">' . esc_html__('Title:') . '
        <input class="widefat" id="' . $this->get_field_id('title') . '" name="' . $this->get_field_name('title') . '" type="text" value="' . $title . '" />
        </label></p>';
        
        echo '<div id="twitter-widget-unauth-block"><p><label for="' . $this->get_field_id('account') . '">' . esc_html__('Twitter username:') . ' <a href="http://support.wordpress.com/widgets/twitter-widget/#twitter-username" target="_blank">( ? )</a>
        <input class="widefat" id="' . $this->get_field_id('account') . '" name="' . $this->get_field_name('account') . '" type="text" value="' . $account . '" />
        </label></p></div>';
        
        echo "<p>
          <label for='".$this->get_field_id('useAuth')."'>
            <input type='checkbox' class='checkbox' id='".$this->get_field_id('useAuth')."' name='".$this->get_field_name('useAuth')."' ";
            if ( $useAuth ) echo ' checked="checked"';
        echo " />Use Authenticated Calls <a href='https://dev.twitter.com/apps/' target='_blank'>( ? )</a>
          </label>
        </p>";
        
        echo "
        <div id='twitter-widget-auth-block'>
        <p><label for='" . $this->get_field_id('consumer_key') . "'>" . esc_html__('Consumer Key (for OAuth):') . "
        <input class='widefat' id='" . $this->get_field_id('consumer_key') . "' name='" . $this->get_field_name('consumer_key') . "' type='text' value='" . $consumer_key . "' />
        </label></p>
        <p><label for='" . $this->get_field_id('consumer_secret') . "'>" . esc_html__('Consumer Secret (for OAuth):') . " 
        <input class='widefat' id='" . $this->get_field_id('consumer_secret') . "' name='" . $this->get_field_name('consumer_secret') . "' type='text' value='" . $consumer_secret . "' />
        </label></p>
        <p><label for='" . $this->get_field_id('oauth_token') . "'>" . esc_html__('OAuth Token (for OAuth):') . " 
        <input class='widefat' id='" . $this->get_field_id('oauth_token') . "' name='" . $this->get_field_name('oauth_token') . "' type='text' value='" . $oauth_token . "' />
        </label></p>
        <p><label for='" . $this->get_field_id('oauth_token_secret') . "'>" . esc_html__('OAuth Token Secret (for OAuth):') . " 
        <input class='widefat' id='" . $this->get_field_id('oauth_token_secret') . "' name='" . $this->get_field_name('oauth_token_secret') . "' type='text' value='" . $oauth_token_secret . "' />
        </label></p>
        <p><label for='" . $this->get_field_id('expire_time') . "'>" . esc_html__('Cache Twitter results (in seconds):') . "
        <input class='widefat' id='" . $this->get_field_id('expire_time') . "' name='" . $this->get_field_name('expire_time') . "' type='text' value='" . $expire_time . "' />
        </label></p>
        </div>
        ";
        
        echo '<p><label for="' . $this->get_field_id('show') . '">' . esc_html__('Maximum number of tweets to show:') . '
            <select id="' . $this->get_field_id('show') . '" name="' . $this->get_field_name('show') . '">';

        for ( $i = 1; $i <= 20; ++$i )
            echo "<option value='$i' " . ( $show == $i ? "selected='selected'" : '' ) . ">$i</option>";

        echo '      </select>
        </label></p>
        <p><label for="' . $this->get_field_id('hidereplies') . '"><input id="' . $this->get_field_id('hidereplies') . '" class="checkbox" type="checkbox" name="' . $this->get_field_name('hidereplies') . '"';
        if ( $hidereplies )
            echo ' checked="checked"';
        echo ' /> ' . esc_html__('Hide replies') . '</label></p>';

        echo '<p><label for="' . $this->get_field_id('includeretweets') . '"><input id="' . $this->get_field_id('includeretweets') . '" class="checkbox" type="checkbox" name="' . $this->get_field_name('includeretweets') . '"';
        if ( $include_retweets )
            echo ' checked="checked"';
        echo ' /> ' . esc_html__('Include retweets') . '</label></p>';

        echo '<p><label for="' . $this->get_field_id('beforetimesince') . '">' . esc_html__('Text to display between tweet and timestamp:') . '
        <input class="widefat" id="' . $this->get_field_id('beforetimesince') . '" name="' . $this->get_field_name('beforetimesince') . '" type="text" value="' . $before_timesince . '" />
        </label></p>';
        
        echo "
        <script type='text/javascript'>
          if(jQuery){
              jQuery.noConflict();
              (function($) { 
                $(document).ready(function() {
                  $('#".$this->get_field_id('useAuth')."').click(checkAuth);
                  var useAuth = '$useAuth';
                  if(useAuth==''){
                    $('#twitter-widget-auth-block p').css({display:'none'});
                  }else{
                    $('#twitter-widget-auth-block p').css({display:'block'});
                  }
                  function checkAuth(event){
                    var isChecked = $(this).attr('checked');
                    if(isChecked==undefined){
                        $('#twitter-widget-auth-block p').css({display:'none'});
                    }else{
                        $('#twitter-widget-auth-block p').css({display:'block'});
                    }
                  }
                });
             })(jQuery);
          }
        </script>
        ";
    }

    /**
     * Link a Twitter user mentioned in the tweet text to the user's page on Twitter.
     *
     * @param array $matches regex match
     * @return string Tweet text with inserted @user link
     */
    function _wpcom_widget_twitter_username( $matches ) { // $matches has already been through wp_specialchars
        return "$matches[1]@<a href='" . esc_url( 'http://twitter.com/' . urlencode( $matches[3] ) ) . "'>$matches[3]</a>";
    }

    /**
     * Link a Twitter hashtag with a search results page on Twitter.com
     *
     * @param array $matches regex match
     * @return string Tweet text with inserted #hashtag link
     */
    function _wpcom_widget_twitter_hashtag( $matches ) { // $matches has already been through wp_specialchars
        return "$matches[1]<a href='" . esc_url( 'http://twitter.com/search?q=%23' . urlencode( $matches[3] ) ) . "'>#$matches[3]</a>";
    }

}

add_action( 'widgets_init', 'auth_twitter_widget_init' );
function auth_twitter_widget_init() {
    register_widget('Auth_Twitter_Widget');
}