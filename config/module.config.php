<?php

return array(
    'service_manager' => array(
        'invokables' => array(
            'AccessDeniedStrategy' => 'Zf2Acl\Mvc\View\Http\AccessDeniedStrategy',
            'AclListener'          => 'Zf2Acl\Permissions\Acl\AclListener',
            'Acl'                  => 'Zf2Acl\Permissions\Acl\Acl',
        ),
    ),
);