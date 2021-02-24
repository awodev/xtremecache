<?php

/*
 * Modified for prestashp 1.7 by Seyi Awofadeju
 * @Website : http://awodev.com
 */

/**
 * Serve cached pages with no request processing
 * @author Salerno Simone
 * @version 1.0.6
 * @license MIT
 */

class XtremeCache extends Module {
	/**
	 * Cache Time-To-Live in seconds
	 * Since cache gets cleaned quite often, use a very high value
	 */
	const CACHE_TTL = 999999;
	
	/**
	 * Cache driver
	 */
	const DEFAULT_DRIVER = 'files';
	
	/**
	 * Cache engine
	 * @var BasePhpFastCache
	 */
	private $fast_cache;
	
	
	public function __construct() {
		$this->name = 'xtremecache';
		$this->tab = 'frontend_features';
		$this->version = '1.7.6.5';
		$this->author = 'Seyi Awofadeju';
		$this->need_instance = 0;

        $this->bootstrap = true;
		parent::__construct();

		$this->displayName = $this->l( 'Xtreme cache' );
		$this->description = $this->l( 'Cache non-dynamic pages in the front office.' );

		$this->fast_cache = $this->getFastCache();
	}

	/**
	 * Handle non-explicitly handled hooks
	 * @param string $name hook name
	 * @param array $arguments
	 */
	public function __call( $name, $arguments ) {
		if ( 0 === strpos( strtolower( $name ), 'hookaction' ) ) {
			$this->fast_cache->clean();
		}
	}

	/**
	 * Install and register hooks
	 * @return bool
	 */
	public function install() {        
		return
			parent::install()
			&& $this->registerHook( 'actionDispatcher')
			&& $this->registerHook( 'actionOutputHTMLBefore' )

			// called with __call()
			&& $this->registerHook( 'actionClearCache' )
			&& $this->registerHook( 'actionClearCompileCache' )
			&& $this->registerHook( 'actionCategoryAdd' )
			&& $this->registerHook( 'actionCategoryUpdate' )
			&& $this->registerHook( 'actionCategoryDelete' )
			&& $this->registerHook( 'actionProductAdd' )
			&& $this->registerHook( 'actionProductUpdate' )
			&& $this->registerHook( 'actionProductDelete' )
			&& $this->registerHook( 'actionProductSave' )
		;
	}

	/**
	 * Uninstall and clear cache
	 * @return bool
	 */
	public function uninstall() {
		//delete all cached files
		$this->fast_cache->clean();
		Configuration::deleteByName( 'XTREMECACHE_DRIVER' );

		return
			$this->unregisterHook('actionDispatcher')
			&& $this->unregisterHook('actionOutputHTMLBefore')
			&& $this->unregisterHook( 'actionClearCache' )
			&& $this->unregisterHook( 'actionClearCompileCache' )
			&& $this->unregisterHook( 'actionCategoryAdd' )
			&& $this->unregisterHook( 'actionCategoryUpdate' )
			&& $this->unregisterHook( 'actionCategoryDelete' )
			&& $this->unregisterHook( 'actionProductAdd' )
			&& $this->unregisterHook( 'actionProductUpdate' )
			&& $this->unregisterHook( 'actionProductDelete' )
			&& $this->unregisterHook( 'actionProductSave' )
			&& parent::uninstall()
		;
	}

	/**
	 * Check if page exists in cache
	 * If it exists, serve and abort
	 * @param array $params
	 */
	public function hookActionDispatcher( $params ) {
		if ( ! $this->isActive() ) {
			return;
		}

		//if front page and not in the checkout process
		if ( Dispatcher::FC_FRONT === $params['controller_type'] && ! in_array( $params['controller_class'], array( 'OrderController', 'OrderOpcController' ) ) ) {

			$cached = $this->fast_cache->get( $this->getCacheKey() );
			if ( $cached !== null ) {
				//empty output buffer
				ob_get_clean();

				// add no cache headers so we control when to show cached pages or not
				header('Cache-Control: no-cache, no-store, must-revalidate');
				header('Pragma: no-cache');
				header('Expires: 0');

				// display
				die( $cached );
		   }
		}
	}

	/**
	 * Cache page content for front pages
	 * @param string $params
	 */
	public function hookActionOutputHTMLBefore( $params ) {
		if ( ! $this->isActive() ) {
			return;
		}
		if ( empty( $params['html'] ) ) {
			return;
		}

		$controller = $this->context->controller;
		if ( ! is_subclass_of( $controller, 'FrontController' ) ) {
			return;
		}
		if ( is_subclass_of( $controller, 'OrderController' ) ) {
			return;
		}
		if ( is_subclass_of( $controller, 'OrderOpcController' ) ) {
			return;
		}

		if ( ! class_exists( 'Minify_HTML' ) ) {
			require _PS_MODULE_DIR_ . $this->name . '/vendor/minify_html.class.php';
		}
		$key = $this->getCacheKey();
		$output = Minify_HTML::minify( $params['html'] );

		//mark page as cached
		//$debugInfo = sprintf(
		//	"<!-- served from cache with key %s [driver: %s] [generated on %s] -->",
		//	$key,
		//	static::DRIVER,
		//	date('Y-m-d H:i:s')
		//);
		//$output = $debugInfo . $output;
		//$this->fast_cache->set($key, $output, static::CACHE_TTL);		

		$output = $output . '<!--' . $key . ' ' . gmdate( 'YmdHis' ) . '-->';
		$this->fast_cache->set( $key, $output, self::CACHE_TTL );
	}

	/**
	 * Check if should use cache
	 * @return boolean
	 */
	private function isActive() {
		//turn off on debug mode
		if ( _PS_MODE_DEV_ || _PS_DEBUG_PROFILING_ ) {
			return false;
		}

		//check that customer is not logged in
		$customer = $this->context->customer;
		if ( $customer && $customer instanceof Customer && $customer->id > 0 ) {
			return false;
		}

		//for guest checkout, check that cart is empty
		$cart = new Cart( $this->context->cookie->id_cart );
		if ( $cart && $cart instanceof Cart && $cart->nbProducts() > 0 ) {
			return false;
		}

		//disable on ajax and non-GET requests
		$active = ! Tools::getValue( 'ajax', false );
		$active = $active && $_SERVER['REQUEST_METHOD'] === 'GET';

		return $active;
	}
	
	/**
	 * Get cache engine
	 * @return BasePhpFastCache 
	 */
	private function getFastCache() {
		if ( ! class_exists( 'phpFastCache' ) ) {
			require _PS_MODULE_DIR_ . $this->name . '/vendor/phpfastcache.php';
		}

		phpFastCache::setup( 'path', _PS_MODULE_DIR_ . $this->name . '/xcache' );

		$driver = Configuration::get( 'XTREMECACHE_DRIVER' );
		if ( empty( $driver ) ) {
			$driver = static::DEFAULT_DRIVER;
		}
		return phpFastCache( $driver );
	}
	
	/**
	 * Map url to cache key
	 * @return string 
	 */
	private function getCacheKey( $url = null ) {
		if ( $url === null ) {
			$url = $_SERVER['REQUEST_URI'];
		}	
		$url = 'device-' . $this->context->getDevice() .
				'-lang-' . $this->context->language->id .
				'-shop-' . $this->context->shop->id .
				'-' . $url
		;
		$url = md5( $url );
		return $url;
	}



	public function getContent() {
		if ( Tools::isSubmit( 'submitModule' ) ) {
			if ( Tools::isSubmit( 'action_clearcache' ) ) {
				$this->fast_cache->clean();
				Tools::redirectAdmin( $this->context->link->getAdminLink( 'AdminModules' ) . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&conf=4&module_name=' . $this->name );
			}
			elseif ( Tools::isSubmit( 'action_save' ) ) {
				Configuration::updateValue( 'XTREMECACHE_DRIVER', Tools::getValue( 'XTREMECACHE_DRIVER' ) );

				$this->displayConfirmation( $this->trans( 'The settings have been updated.', array(), 'Admin.Notifications.Success' ) );
				Tools::redirectAdmin( $this->context->link->getAdminLink( 'AdminModules' ) . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&conf=4&module_name=' . $this->name );
			}
		}
		return $this->render_settings_form();
	}

	public function render_settings_form() {
		$fields_form = array(
			'form' => array(
				'legend' => array(
					'title' => $this->trans('Settings', array(), 'Admin.Global'),
					'icon' => 'icon-cogs'
				),
				'input' => array(
					array(
						'type' => 'free',
						'label' => '
							<button type="submit" class="btn btn-danger pull-left" name="action_clearcache"><i class="fa fa-archive"></i> Clear Cache</button>
						',
					),
					array(
						'type' => 'select',
						'label' => $this->trans('Driver'),
						'name' => 'XTREMECACHE_DRIVER',
						'size' => 1,
						'options' => array(
							'query' =>  array(
								array( 'name' => 'files', 'title' => 'File' ),
								array( 'name' => 'apc', 'title' => 'Alternative PHP Cache (APC)' ),
								array( 'name' => 'memcache', 'title' => 'Memcache' ),
								array( 'name' => 'memcached', 'title' => 'Memcached' ),
								array( 'name' => 'redis', 'title' => 'Redis' ),
								array( 'name' => 'sqlite', 'title' => 'SQLite' ),
								array( 'name' => 'wincache', 'title' => 'WinCache' ),
								array( 'name' => 'xcache', 'title' => 'Xcache' ),
							),
							'id' => 'name',
							'name' => 'title',
						),
                        'desc' => $this->trans('If driver is not found, defaults to using File driver'),
					),
				),
				//'buttons' => array(
				//	'clear-cache' => array(
				//		'title' => $this->l('Clear Cache'),
				//		'name' => 'action_clearcache',
				//	'type' => 'submit',
				//		'class' => 'btn btn-default pull-left',
				//		'icon' => 'process-icon-save',
				//	),
				//),
				'submit' => array(
					'title' => $this->trans('Save', array(), 'Admin.Actions'),
					'name' =>'action_save',
				)
			),
		);
        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table =  $this->table;
        $helper->submit_action = 'submitModule';
        $helper->tpl_vars = array(
            'fields_value' => $this->getConfigFieldsValues(),
        );

        return $helper->generateForm( array( $fields_form ) );
	}

	public function getConfigFieldsValues() {
		return array(
			'XTREMECACHE_DRIVER' => Tools::getValue('XTREMECACHE_DRIVER', Configuration::get('XTREMECACHE_DRIVER')),
		);
	}

}
