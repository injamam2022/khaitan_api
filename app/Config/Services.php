<?php

namespace Config;

use CodeIgniter\Config\BaseService;

/**
 * Services Configuration file.
 *
 * Services are simply other classes/libraries that the system uses
 * to do its job. This is used by CodeIgniter to allow the core of the
 * framework to be swapped out easily without affecting the usage within
 * the rest of your application.
 *
 * This file holds any application-specific services, or service overrides
 * that you might need. An example has been included with the general
 * method format you should use for your service methods. For more examples,
 * see the core Services file at system/Config/Services.php.
 */
class Services extends BaseService
{
    /**
     * EasyEcom API client (Self-Ship). Use for product sync, order push, cancel.
     *
     * @return \App\Libraries\EasyEcomClient
     */
    public static function easyecom(bool $getShared = true)
    {
        if ($getShared) {
            return static::getSharedInstance('easyecom');
        }
        return new \App\Libraries\EasyEcomClient();
    }

    /**
     * EasyEcom Carrier Outbound service (Delhivery via EasyEcom).
     * Handles: authenticateCarrier, listCarriers, createShipment, cancelShipment, authorizeTracking, updateTrackingStatus.
     *
     * @return \App\Services\EasyEcomCarrierService
     */
    public static function easyecomCarrier(bool $getShared = true)
    {
        if ($getShared) {
            return static::getSharedInstance('easyecomCarrier');
        }
        return new \App\Services\EasyEcomCarrierService();
    }
}
