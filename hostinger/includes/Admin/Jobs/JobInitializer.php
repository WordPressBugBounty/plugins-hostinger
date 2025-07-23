<?php

namespace Hostinger\Admin\Jobs;

use Hostinger\Admin\Proxy;
use Hostinger\Mcp\EventHandlerFactory;

defined( 'ABSPATH' ) || exit;

class JobInitializer {

    public function __construct( Proxy $proxy ) {
        new NotifyMcpJob( new ActionScheduler(), new EventHandlerFactory( $proxy ) );
    }
}
