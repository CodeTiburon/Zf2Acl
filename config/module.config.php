<?php

return array(
    'service_manager' => array(
        'invokables' => array(
            'AccessDeniedStrategy' => 'Zf2Acl\Mvc\View\Http\AccessDeniedStrategy',
            'GuestRedirectUserSetHttpCode' => 'Zf2Acl\Mvc\View\Http\GuestRedirectUserSetHttpCode',
            'AclListener'          => 'Zf2Acl\Permissions\Acl\AclListener',
            'Acl'                  => 'Zf2Acl\Permissions\Acl\Acl',
        ),
    ),
);