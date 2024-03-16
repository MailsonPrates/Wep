<?php

use App\Core\Engine\Builder\Vendor;

define('DIR_MODULES', 'src/Modules');


$vendor_routes = Vendor::getRoutes();
$result = [];

echo json_encode($vendor_routes);
exit();

test("Vendor: routes", function() use ($vendor_routes, $result){
    expect($vendor_routes)->toBe($result);
})->group("vendor");