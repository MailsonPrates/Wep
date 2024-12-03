<?php

namespace App\Core;

use App\Core\Engine\Builder\Vendor\Vendor as BuilderVendor;
use App\Core\VendorMethod;
use Exception;

trait Vendor
{
    public function __call($currentResource, $arguments)
    {
        return self::resource($currentResource, $this);
    }

    public static function resource($currentResource, $vendorInstance=null)
    {
        $vendor_namespace = get_called_class();
        $vendor = explode('\\', $vendor_namespace);
        $vendor = end($vendor);

        $vendorInstance = $vendorInstance ?: new $vendor_namespace;

        $vendor_data = BuilderVendor::getMap($vendor) ?? [];

        if ( empty($vendor_data) ) throw new Exception("As configurações para o módulo vendor '$vendor' não foram encontradas. Certifique-se de que o arquivo de config. do módulo foi corretamente criado e o app foi atualizado pelo comando > php app update");

        $resources = $vendor_data['resources'][$currentResource] ?? [];

        if ( empty($resources) ) throw new Exception("Resource '$currentResource' não encontrado para o módulo '$vendor'. Certifique-se de que o arquivo de config. do módulo foi corretamente criado e o app foi atualizado pelo comando > php app update");

        $headers = $vendor_data['headers'] ?? [];
        $hooks = $vendor_data['hooks'] ?? [];

        return new VendorMethod([
            //'headers' => $headers,
            'resources' => $resources,
            'vendor' => $vendor,
            'vendor_instance' => $vendorInstance,
            //'hooks' => $hooks
        ]);
    }
}