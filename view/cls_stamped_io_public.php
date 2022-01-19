<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class Woo_stamped_public {

	private static $stampedDBData;

    public function __construct() {
        $this->Woo_stamped_display_settings();
		self::$stampedDBData = null;
    }

	public static function stamped_io_store_data($product_id, $product_title)
	{
		$show_stamped_rich_snippet = Woo_stamped_api::get_enable_rich_snippet();
		$enabled_stamped_cache = Woo_stamped_api::get_enable_reviews_cache();

		if ($show_stamped_rich_snippet == 'yes' || $enabled_stamped_cache == 'yes')
		{
			self::$stampedDBData = get_post_meta($product_id, "stamped_io_product_reviews_new", true);
			$ttl = (int)get_post_meta($product_id, "stamped_io_product_ttl", true);

			if (self::$stampedDBData == null || self::$stampedDBData == "" || $ttl < time()) {
				//$outcome = (array) Woo_stamped_api::send_request("/richSnippet?productId={$product_id}", array(), "GET");
			
				$outcome = (array) Woo_stamped_api::get_request($product_id, $product_title, array(), "GET");
				if (isset($outcome["count"])) {
					if (!isset($outcome["ttl"])){
						$ttl = (int)86400 + time();
					} else {
						$ttl = (int)$outcome["ttl"] + time();
					}

					if ($enabled_stamped_cache != "yes") {
						$outcome["widget"] = "";
					}
				
					update_post_meta($product_id, "stamped_io_product_reviews_new", $outcome);
					update_post_meta($product_id, "stamped_io_product_ttl", $ttl);
					self::$stampedDBData = $outcome;
				}
			}
		}
	}

	function stamped_io_woocommerce_structured_data_product($markup, $product) {
		$product_id = $product->get_id();
		$product_title = $product->get_title();
		
		self::stamped_io_store_data($product_id, $product_title);
		
		if (self::$stampedDBData && self::$stampedDBData["rating"] != "0" && self::$stampedDBData["rating"] != 0){
			$markup['aggregateRating'] = array(
				'@type' => 'AggregateRating',
				'ratingValue' => self::$stampedDBData["rating"],
				'reviewCount' => self::$stampedDBData["count"],
				'worstRating' => 1,
				'bestRating' => 5,
				'itemReviewed'=> $product->get_title(),
			);
		}

		return $markup;
	}

    /**
     * All Setting Regarding Display coded here
     */
    public function Woo_stamped_display_settings() {
	
		$show_stamped_rich_snippet = Woo_stamped_api::get_enable_rich_snippet();

		// Cheking Archive Options is enable or disabled
        $show_stamped_rating_on_archive = Woo_stamped_api::Show_stamped_rating_on_archive();

        if ($show_stamped_rating_on_archive == "yes") {
            add_action("woocommerce_after_shop_loop_item_title", array($this, "Woo_stamped_review_badge"), 6);
        }

        $show_stamped_rating_on_product = Woo_stamped_api::get_rating_enable_on_product();
        if ($show_stamped_rating_on_product == "yes") {
            add_action('woocommerce_single_product_summary', array($this, 'Woo_stamped_review_badge_single_product'), 9);
        }

        $selected_area = Woo_stamped_api::get_selected_area_of_stamped_area();
        if ($selected_area == "below") {
            add_action('woocommerce_after_single_product_summary', array($this, 'Woo_stamped_review_box'), 4);
        }

        if ($selected_area == "inside") {
            add_filter("woocommerce_product_tabs", array($this, "Woo_stamped_add_widget_inside_tabs"), 11, 1);
        }

		if ($show_stamped_rich_snippet == "yes") {
			add_filter('woocommerce_structured_data_product', array($this, "stamped_io_woocommerce_structured_data_product"), 10, 2);
		}

        $disallow_natice_rating = Woo_stamped_api::disallow_natice_rating();

        if ($disallow_natice_rating == "yes") {
            remove_action("woocommerce_after_shop_loop_item_title", "woocommerce_template_loop_rating", 5);
            remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_rating', 10);
            add_filter("woocommerce_product_tabs", array($this, "Woo_stamped_remove_review_box_at_single_product"), 12, 1);
        }

        add_action('woocommerce_thankyou', array($this, 'stamped_io_woocommerce_conversion_tracking'), 0);

        // Loyalty & Rewards
		$show_stamped_rewards = Woo_stamped_api::get_enable_rewards();

		if ($show_stamped_rewards == "yes") {
			add_action('wp_footer', array($this, "stamped_io_woocommerce_rewards_launcher"), 10, 2);
		}
    }

	public function stamped_io_woocommerce_rewards_launcher() {
        $domainName = Woo_stamped_api::get_site_url();
		$public_key = Woo_stamped_api::get_public_keys();
		$private_key = Woo_stamped_api::get_private_keys();
        $htmlLauncher = "<div id='stamped-rewards-init' class='stamped-rewards-init' data-key-public='%s' %s></div>";
        $htmlLoggedInAttributes = "";

        if (is_user_logged_in()){
            $current_user = wp_get_current_user();

		    $customer = wp_get_current_user();
            $message = get_current_user_id() . $current_user->user_email;

            // to lowercase hexits
            $hmacVal = hash_hmac('sha256', $message, $private_key);

			$htmlLoggedInAttributesVal = "data-key-auth='%s' data-customer-id='%d' data-customer-email='%s' data-customer-first-name='%s' data-customer-last-name='%s' data-customer-orders-count='%d' data-customer-tags='%s' data-customer-total-spent='%d'";
			$htmlLoggedInAttributes = sprintf($htmlLoggedInAttributesVal, $hmacVal, get_current_user_id(), $current_user->user_email, esc_html( $current_user->user_firstname ), esc_html( $current_user->user_lastname ), "", "", "" );
        }

		echo "<!-- Stamped.io Rewards Launcher -->\n";
		echo sprintf($htmlLauncher, $public_key, $htmlLoggedInAttributes);
		echo "\n<!-- Stamped.io Rewards Launcher -->\n";
    }

	public function stamped_io_woocommerce_conversion_tracking( $order_id ) {
		$order = wc_get_order( $order_id );
		
        $domainName = Woo_stamped_api::get_site_url();
		$public_key = Woo_stamped_api::get_public_keys();

		if ($order){
			$code = '<img src="//stamped.io/conversion_tracking.gif?shopUrl=%s&apiKey=%s&orderId=%d&orderAmount=%d&orderCurrency=%s" />';

			echo "<!-- Stamped.io Conversion Tracking plugin -->\n";
			echo sprintf($code, $domainName, $public_key, $order_id, $order->get_total(), $order->get_currency());
			echo "\n<!-- Stamped.io Conversion Tracking plugin -->\n";
		}
    }

    /**
     * Removing Review Tabs from single product when WC Native Setting is enabled
     * @param type $tabs global tabs array
     * @return type
     */
    public function Woo_stamped_remove_review_box_at_single_product($tabs) {
        if (is_array($tabs) && count($tabs) > 0) {
            foreach ($tabs as $key => $tab) {
                if ($key == "reviews") {
                    unset($tabs[$key]);
                }
            }
        }
        return $tabs;
    }

    public function Woo_stamped_add_widget_inside_tabs($tabs) {
        if (is_array($tabs) && count($tabs) > 0) {
            $priority = 30;
            foreach ($tabs as $key => $tab) {
                if ($key == "reviews") {
                    $priority = (int) $tab["priority"];
                }
            }
            $priority++;
            $tabs["stamped_reviews_widget"] = array(
                "title" => __("Reviews", "woocommerce"),
                "callback" => array("Woo_stamped_public", "Woo_stamped_review_box"),
                "priority" => $priority,
            );
        }
        return $tabs;
    }

    /**
     * Create and display Review Box  with Post Review form 
     * This is Public Static function call directly from any class Like Woo_stamped_public::Woo_stamped_review_box()
     * @global type $product
     */
    public static function Woo_stamped_review_box() {
        global $product;
        //$desc = strip_tags($product->post->post_excerpt ? $product->post->post_excerpt : $product->post->post_content);
		$product_id = '';
		$product_title = '';
		$product_sku = '';
		$img = '';

		if (isset($product) && is_object($product)){
			$img = get_the_post_thumbnail_url($product->get_id());
			$product_id = $product->get_id();
			$product_title = $product->get_title();
			$product_sku = $product->get_sku();
		}
        
		$content = "";

		self::stamped_io_store_data($product_id, $product_title);

		if (self::$stampedDBData != null) 
		{
			if (self::$stampedDBData && self::$stampedDBData["rating"] != "0" && self::$stampedDBData["rating"] != 0 && self::$stampedDBData["widget"] != ""){
				$stamped_widget = self::$stampedDBData["widget"];
				$stamped_product = self::$stampedDBData["product"];

				$content = str_replace("<div class=\"stamped-content\"> </div>","<link rel=\"stylesheet\" type=\"text/css\" href=\"//cdn1.stamped.io/files/widget.min.css\" /><div class=\"stamped-content\">".$stamped_widget."</div>",$stamped_product);
			}
		}

        echo sprintf('<div id="stamped-main-widget" class="stamped stamped-main-widget" data-product-id="%d" data-product-sku="%s" data-name="%s" data-url="%s" data-image-url="%s" data-widget-language="">%s</div>', $product_id, $product_sku, $product_title, get_the_permalink(), $img, $content); // data-description="%s" / $desc
    }

    /**
     * Create and display Review Badge
     * This is Public Static function call directly from any class Like Woo_stamped_public::Woo_stamped_review_badge()
     * @global type $product
     */
    public static function Woo_stamped_review_badge() {
        global $product;
		
		$product_id = '';
		$product_title = '';
		$product_sku = '';
        
        try {
		        if (isset($product) && is_object($product)){
			        $product_id = $product->get_id();
			        $product_title = $product->get_title();
			        $product_sku = $product->get_sku();
		        }
        } catch (Exception $e) {
           
        }

        echo sprintf('<span class="stamped-product-reviews-badge" data-id="%d" data-name="%s" data-product-sku="%s" data-url="%s"></span>', $product_id, $product_title, $product_sku, get_the_permalink());
    }

    public static function Woo_stamped_review_badge_single_product() {
        global $product;
		
		$product_id = '';
		$product_title = '';
		$product_sku = '';

		if (isset($product) && is_object($product)){
			$product_id = $product->get_id();
			$product_title = $product->get_title();
			$product_sku = $product->get_sku();
		}

        echo sprintf('<span class="stamped-product-reviews-badge stamped-main-badge" data-id="%d" data-name="%s" data-product-sku="%s" data-url="%s"></span>', $product_id, $product_title, $product_sku, get_the_permalink());
        echo sprintf('<span class="stamped-product-reviews-badge stamped-main-badge" data-id="%d" data-name="%s" data-product-sku="%s" data-url="%s" data-type="qna" style="display:none;"></span>', $product_id, $product_title, $product_sku, get_the_permalink());
    }

    public static function Woo_stamped_aggregate_rating($data) {
        if (empty($data)) {
            return "";
        }

		if ($data["reviewsAverage"] == "0" || $data["reviewsAverage"] == 0){
            return "";
        }

        ob_start();
        global $product;
        ?>
        <div itemprop="aggregateRating" itemscope="" itemtype = "http://schema.org/AggregateRating">
            <span itemprop = "itemReviewed"><?php echo $product->get_title(); ?></span>
            <?php _e("has a rating of") ?> <span itemprop = "ratingValue"><?php echo $data["reviewsAverage"]; ?></span> stars
            <?php _e("based on") ?> <span itemprop = "ratingCount"><?php echo $data["reviewsCount"]; ?></span> reviews.
        </div>
        <?php
        return ob_get_clean();
    }
}

new Woo_stamped_public();
