
<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */

// --------------------------------------------------
// Home and database test
// --------------------------------------------------
$routes->get('/', 'Home::index');
$routes->get('api/database-test', 'DatabaseTest::index');

// --------------------------------------------------
// Authentication
// --------------------------------------------------
$routes->post('api/auth/login', 'Api\AuthController::login');

$routes->options('api/auth/login', static function () {
    return service('response')->setStatusCode(204);
});

// --------------------------------------------------
// Sales invoice API
// --------------------------------------------------
$routes->group('api/sales', static function (RouteCollection $routes) {

    $routes->get('invoices', 'Api\SalesInvoicesController::index');
    $routes->post('invoices', 'Api\SalesInvoicesController::create');

    $routes->options('invoices', static function () {
        return service('response')->setStatusCode(204);
    });

    $routes->get(
        'invoices/(:segment)',
        'Api\SalesInvoicesController::show/$1'
    );

    $routes->put(
        'invoices/(:segment)',
        'Api\SalesInvoicesController::update/$1'
    );

    $routes->options('invoices/(:segment)', static function () {
        return service('response')->setStatusCode(204);
    });

    $routes->post(
        'invoices/(:segment)/submit',
        'Api\SalesInvoicesController::submit/$1'
    );

    $routes->post(
        'invoices/(:segment)/approve',
        'Api\SalesInvoicesController::approve/$1'
    );

    $routes->post(
        'invoices/(:segment)/reject',
        'Api\SalesInvoicesController::reject/$1'
    );

    $routes->post(
        'invoices/(:segment)/confirm-payment',
        'Api\SalesInvoicesController::confirmPayment/$1'
    );

    $routes->post(
        'attachments/upload',
        'Api\SalesInvoicesController::uploadAttachment'
    );

    $routes->options('attachments/upload', static function () {
        return service('response')->setStatusCode(204);
    });

    $routes->get(
        'invoices/attachments/(:segment)',
        'Api\SalesInvoicesController::viewAttachment/$1'
    );

    $routes->options(
        'invoices/attachments/(:segment)',
        static function () {
            return service('response')->setStatusCode(204);
        }
    );

    $routes->options('invoices/(:segment)/(:segment)', static function () {
        return service('response')->setStatusCode(204);
    });

    $routes->get(
        'finance/sales-review',
        'Api\FinanceSalesReviewController::index'
    );

    $routes->post(
        'finance/sales-review/(:segment)/approve',
        'Api\FinanceSalesReviewController::approve/$1'
    );

    $routes->post(
        'finance/sales-review/(:segment)/reject',
        'Api\FinanceSalesReviewController::reject/$1'
    );

    $routes->options('finance/sales-review', static function () {
        return service('response')->setStatusCode(204);
    });

    $routes->options(
        'finance/sales-review/(:segment)/(:segment)',
        static function () {
            return service('response')->setStatusCode(204);
        }
    );

    $routes->get(
        'aggregate-types',
        'Api\SalesInvoicesController::aggregateTypes'
    );

    $routes->options('aggregate-types', static function () {
        return service('response')->setStatusCode(204);
    });

    $routes->get(
        'inventory/monthly-reports',
        'Api\SalesInventoryController::index'
    );

    $routes->options('inventory/monthly-reports', static function () {
        return service('response')->setStatusCode(204);
    });
});

// --------------------------------------------------
// Finance Royalty Reports API
// --------------------------------------------------
$routes->group('api/finance/royalty-reports', static function (RouteCollection $routes) {

    $routes->get('/', 'Api\FinanceRoyaltyReportsController::index');

    $routes->get(
        'batches',
        'Api\FinanceRoyaltyReportsController::batches'
    );

    $routes->post(
        'batches',
        'Api\FinanceRoyaltyReportsController::finalizeBatch'
    );

    $routes->options('/', static function () {
        return service('response')->setStatusCode(204);
    });

    $routes->options('batches', static function () {
        return service('response')->setStatusCode(204);
    });
});

// --------------------------------------------------
// Finance Expenses API
// --------------------------------------------------
$routes->group('api/finance/expenses', static function (RouteCollection $routes) {

    $routes->get('/', 'Api\FinanceExpensesController::index');

    $routes->post('/', 'Api\FinanceExpensesController::create');

    $routes->get(
        'review-queue',
        'Api\FinanceExpensesController::reviewQueue'
    );

    $routes->post(
        '(:segment)/submit',
        'Api\FinanceExpensesController::submit/$1'
    );

    $routes->post(
        '(:segment)/approve',
        'Api\FinanceExpensesController::approve/$1'
    );

    $routes->post(
        '(:segment)/reject',
        'Api\FinanceExpensesController::reject/$1'
    );

    $routes->get(
        '(:segment)',
        'Api\FinanceExpensesController::show/$1'
    );

    $routes->put(
        '(:segment)',
        'Api\FinanceExpensesController::update/$1'
    );

    $routes->delete(
        '(:segment)',
        'Api\FinanceExpensesController::delete/$1'
    );

    $routes->options('/', static function () {
        return service('response')->setStatusCode(204);
    });

    $routes->options('review-queue', static function () {
        return service('response')->setStatusCode(204);
    });

    $routes->options('(:segment)', static function () {
        return service('response')->setStatusCode(204);
    });

    $routes->options('(:segment)/(:segment)', static function () {
        return service('response')->setStatusCode(204);
    });
});



// --------------------------------------------------
// Finance Payroll API
// --------------------------------------------------
$routes->group('api/finance/payroll-runs', static function (RouteCollection $routes) {

    $routes->get(
        '/',
        'Api\FinancePayrollController::index'
    );

    $routes->get(
        '(:segment)',
        'Api\FinancePayrollController::show/$1'
    );

    $routes->post(
        '(:segment)/approve',
        'Api\FinancePayrollController::approve/$1'
    );

    $routes->post(
        '(:segment)/reject',
        'Api\FinancePayrollController::reject/$1'
    );

    $routes->post(
        '(:segment)/mark-paid',
        'Api\FinancePayrollController::markPaid/$1'
    );

    $routes->options('/', static function () {
        return service('response')->setStatusCode(204);
    });

    $routes->options('(:segment)', static function () {
        return service('response')->setStatusCode(204);
    });

    $routes->options('(:segment)/(:segment)', static function () {
        return service('response')->setStatusCode(204);
    });
    $routes->put(
    'payroll-runs/(:segment)/employees/(:segment)',
    'FinancePayrollController::updateEmployee/$1/$2'
);
});


// --------------------------------------------------
// Finance Monthly Inventory Reports API
// --------------------------------------------------
$routes->group('api/finance/monthly-inventory-reports', static function (RouteCollection $routes) {

    $routes->get(
        '/',
        'Api\FinanceMonthlyInventoryReportsController::index'
    );

    $routes->get(
        'aggregate-types',
        'Api\FinanceMonthlyInventoryReportsController::aggregateTypes'
    );

    $routes->post(
        'draft',
        'Api\FinanceMonthlyInventoryReportsController::saveDraft'
    );

    $routes->post(
        'submit',
        'Api\FinanceMonthlyInventoryReportsController::submitReport'
    );

    $routes->post(
        'correct',
        'Api\FinanceMonthlyInventoryReportsController::correct'
    );

    $routes->get(
        '(:segment)',
        'Api\FinanceMonthlyInventoryReportsController::showPeriod/$1'
    );

    $routes->options('/', static function () {
        return service('response')->setStatusCode(204);
    });

    $routes->options('aggregate-types', static function () {
        return service('response')->setStatusCode(204);
    });

    $routes->options('draft', static function () {
        return service('response')->setStatusCode(204);
    });

    $routes->options('submit', static function () {
        return service('response')->setStatusCode(204);
    });

    $routes->options('correct', static function () {
        return service('response')->setStatusCode(204);
    });

    $routes->options('(:segment)', static function () {
        return service('response')->setStatusCode(204);
    });
});


$routes->group('api/employees', static function (RouteCollection $routes) {
    $routes->get('/', 'Api\EmployeesController::index');
    $routes->post('/', 'Api\EmployeesController::create');

    // Employee photo routes must come before (:segment).
    $routes->get(
        'photos/(:segment)',
        'Api\EmployeesController::viewPhoto/$1'
    );

    $routes->options('photos/(:segment)', static function () {
        return service('response')->setStatusCode(204);
    });

    $routes->get('(:segment)', 'Api\EmployeesController::show/$1');
    $routes->put('(:segment)', 'Api\EmployeesController::update/$1');
    $routes->put('(:segment)/status', 'Api\EmployeesController::setStatus/$1');

    $routes->options('/', static function () {
        return service('response')->setStatusCode(204);
    });

    $routes->options('(:segment)', static function () {
        return service('response')->setStatusCode(204);
    });

    $routes->options('(:segment)/status', static function () {
        return service('response')->setStatusCode(204);
    });
});


/* -------------------------------------------------------------------------- */
/*                            ATTENDANCE API ROUTES                           */
/* -------------------------------------------------------------------------- */

$routes->options('api/(:any)', static function () {
    return service('response')->setStatusCode(204);
});

$routes->group('api/attendance', ['filter' => 'authtoken'], static function (RouteCollection $routes) {

    /*
     * ATTENDANCE SETTINGS
     * Keep these BEFORE the generic (:segment) routes.
     */
    $routes->get('settings', 'Api\AttendanceSettingsController::show');
    $routes->put('settings', 'Api\AttendanceSettingsController::update');

    /*
     * DAILY AND MONTHLY ATTENDANCE
     */
    $routes->get('/', 'Api\AttendanceController::index');
    $routes->get('monthly', 'Api\AttendanceController::monthly');
    $routes->get('day/(:segment)', 'Api\AttendanceController::day/$1');

    /*
     * ATTENDANCE RECORD CRUD
     */
    $routes->post('/', 'Api\AttendanceController::create');
    $routes->get('(:segment)', 'Api\AttendanceController::show/$1');
    $routes->put('(:segment)', 'Api\AttendanceController::update/$1');
    $routes->delete('(:segment)', 'Api\AttendanceController::delete/$1');

    /*
     * ATTENDANCE IMPORT
     */
    $routes->get('imports', 'Api\AttendanceImportController::index');
    $routes->post('import/preview', 'Api\AttendanceImportController::preview');
    $routes->get('import/(:segment)', 'Api\AttendanceImportController::show/$1');
    $routes->put('import/(:segment)/rows', 'Api\AttendanceImportController::updateRows/$1');
    $routes->post('import/(:segment)/approve', 'Api\AttendanceImportController::approve/$1');
    $routes->post('import/(:segment)/cancel', 'Api\AttendanceImportController::cancel/$1');
});


// --------------------------------------------------
// Leave Requests API
// --------------------------------------------------
$routes->group('api/leave-requests', static function ($routes) {

    $routes->get(
        '/',
        'Api\LeaveController::index'
    );

    $routes->post(
        '/',
        'Api\LeaveController::create'
    );

    $routes->get(
        '(:segment)',
        'Api\LeaveController::show/$1'
    );

    $routes->post(
        '(:segment)/approve',
        'Api\LeaveController::approve/$1'
    );

    $routes->post(
        '(:segment)/reject',
        'Api\LeaveController::reject/$1'
    );

    $routes->options('/', static fn () => service('response')->setStatusCode(204));

    $routes->options('(:segment)', static fn () => service('response')->setStatusCode(204));

    $routes->options('(:segment)/(:segment)', static fn () => service('response')->setStatusCode(204));
});

// --------------------------------------------------
// Payroll API
// --------------------------------------------------
$payrollOptions = static function () {
    return service('response')
        ->setStatusCode(204)
        ->setHeader('Access-Control-Allow-Origin', 'http://localhost:5173')
        ->setHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS')
        ->setHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With')
        ->setHeader('Access-Control-Max-Age', '86400');
};

$routes->options('api/hr/payroll', $payrollOptions);
$routes->options('api/hr/payroll/config', $payrollOptions);

$routes->group('api/hr/payroll', static function ($routes) {

    $routes->get('/', 'Api\PayrollController::index');

    $routes->get(
        'config',
        'Api\PayrollController::getConfig'
    );

    $routes->put(
        'config',
        'Api\PayrollController::updateConfig'
    );

    $routes->post('/', 'Api\PayrollController::create');

    $routes->get(
        '(:segment)',
        'Api\PayrollController::show/$1'
    );

    $routes->post(
        '(:segment)/calculate',
        'Api\PayrollController::calculate/$1'
    );

    $routes->post(
        '(:segment)/submit',
        'Api\PayrollController::submit/$1'
    );

    $routes->post(
        '(:segment)/resubmit',
        'Api\PayrollController::resubmit/$1'
    );

    $routes->put(
        '(:segment)/employees/(:segment)',
        'Api\PayrollController::updateEmployee/$1/$2'
    );
});

// --------------------------------------------------
// Admin User Management API
// --------------------------------------------------
$routes->group('api/admin/users', ['filter' => 'authtoken'], static function ($routes) {

    $routes->options('/', static function () {
        return service('response')->setStatusCode(204);
    });

    $routes->options('audit', static function () {
        return service('response')->setStatusCode(204);
    });

    $routes->options('(:segment)', static function () {
        return service('response')->setStatusCode(204);
    });

    $routes->options('(:segment)/status', static function () {
        return service('response')->setStatusCode(204);
    });

    $routes->options('(:segment)/password-reset', static function () {
        return service('response')->setStatusCode(204);
    });

    $routes->get(
        '/',
        'Api\UserManagementController::index'
    );

    $routes->get(
        'audit',
        'Api\UserManagementController::auditLog'
    );

    $routes->post(
        '/',
        'Api\UserManagementController::create'
    );

    $routes->put(
        '(:segment)',
        'Api\UserManagementController::update/$1'
    );

    $routes->patch(
        '(:segment)/status',
        'Api\UserManagementController::setStatus/$1'
    );

    $routes->post(
        '(:segment)/password-reset',
        'Api\UserManagementController::passwordReset/$1'
    );
});

// --------------------------------------------------
// Admin Expenses API - CORS Preflight
// --------------------------------------------------
$routes->group('api/admin/expenses', static function ($routes) {

    $routes->options('/', static function () {
        return service('response')->setStatusCode(204);
    });

    $routes->options('attachments/upload', static function () {
        return service('response')->setStatusCode(204);
    });

    $routes->options('(:segment)', static function () {
        return service('response')->setStatusCode(204);
    });

    $routes->options('(:segment)/submit', static function () {
        return service('response')->setStatusCode(204);
    });
});

// --------------------------------------------------
// Admin Expenses API - Actual Routes
// --------------------------------------------------
$routes->group('api/admin/expenses', static function ($routes) {

    $routes->get(
        '/',
        'Api\AdminExpensesController::index'
    );

    $routes->get(
        '(:segment)',
        'Api\AdminExpensesController::show/$1'
    );

    $routes->post(
        '/',
        'Api\AdminExpensesController::create'
    );

    $routes->put(
        '(:segment)',
        'Api\AdminExpensesController::update/$1'
    );

    $routes->post(
        'attachments/upload',
        'Api\AdminExpensesController::uploadAttachment'
    );

    $routes->post(
        '(:segment)/submit',
        'Api\AdminExpensesController::submit/$1'
    );
});

// --------------------------------------------------
// Admin Financial Reports API
// --------------------------------------------------
$routes->group('api/admin/financial-reports', static function ($routes) {

    $routes->options('/', static function () {
        return service('response')->setStatusCode(204);
    });

    $routes->get(
        '/',
        'Api\AdminFinancialReportsController::index'
    );
});

// --------------------------------------------------
// Admin Operational Settings API
// --------------------------------------------------
$routes->group(
    'api/admin/operational-settings',
    ['filter' => 'authtoken'],
    static function ($routes) {

        $routes->get(
            '/',
            'Api\OperationalSettingsController::index'
        );

        $routes->put(
            '/',
            'Api\OperationalSettingsController::update'
        );

        $routes->get(
            'aggregate-types',
            'Api\OperationalSettingsController::aggregateTypes'
        );

        $routes->post(
            'aggregate-types',
            'Api\OperationalSettingsController::createAggregateType'
        );

        $routes->put(
            'aggregate-types/(:num)',
            'Api\OperationalSettingsController::updateAggregateType/$1'
        );

        $routes->delete(
            'aggregate-types/(:num)',
            'Api\OperationalSettingsController::deleteAggregateType/$1'
        );

        $routes->options('/', static function () {
            return service('response')->setStatusCode(204);
        });

        $routes->options('aggregate-types', static function () {
            return service('response')->setStatusCode(204);
        });

        $routes->options('aggregate-types/(:num)', static function () {
            return service('response')->setStatusCode(204);
        });
    }
);

// --------------------------------------------------
// Profile API - CORS Preflight
// --------------------------------------------------
$profileOptions = static function () {
    return service('response')
        ->setStatusCode(204)
        ->setHeader('Access-Control-Allow-Origin', 'http://localhost:5173')
        ->setHeader('Vary', 'Origin')
        ->setHeader(
            'Access-Control-Allow-Methods',
            'GET, PUT, OPTIONS'
        )
        ->setHeader(
            'Access-Control-Allow-Headers',
            'Authorization, Content-Type, Accept, Origin, X-Requested-With'
        )
        ->setHeader('Access-Control-Max-Age', '86400');
};

$routes->options('api/profile', $profileOptions);
$routes->options('api/profile/email', $profileOptions);
$routes->options('api/profile/password', $profileOptions);

// --------------------------------------------------
// Profile API - Authenticated Endpoints
// --------------------------------------------------
$routes->group(
    'api/profile',
    ['filter' => 'authtoken'],
    static function ($routes) {

        $routes->get(
            '/',
            'Api\ProfileController::show'
        );

        $routes->put(
            'email',
            'Api\ProfileController::updateEmail'
        );

        $routes->put(
            'password',
            'Api\ProfileController::updatePassword'
        );
    }
);




/* --------------------------------------------------
 * Credit Contracts API
 * -------------------------------------------------- */

// CORS preflight requests must not require authentication.
$routes->group('api/credit-contracts', static function ($routes) {

    $routes->options('/', static function () {
        return service('response')->setStatusCode(204);
    });

    $routes->options('(:segment)', static function () {
        return service('response')->setStatusCode(204);
    });

    $routes->options('(:segment)/(:segment)', static function () {
        return service('response')->setStatusCode(204);
    });

    $routes->options('(:segment)/orders', static function () {
        return service('response')->setStatusCode(204);
    });

    $routes->options('(:segment)/orders/(:segment)', static function () {
        return service('response')->setStatusCode(204);
    });

    $routes->options('(:segment)/orders/(:segment)/cancel', static function () {
        return service('response')->setStatusCode(204);
    });
});

// Actual Credit Contract endpoints.
// Authentication and role authorization are handled inside
// CreditContractsController using the existing bearer session.
$routes->group('api/credit-contracts', static function ($routes) {

    // Contract list and CRUD
    $routes->get('/', 'Api\CreditContractsController::index');
    $routes->post('/', 'Api\CreditContractsController::create');

    $routes->get('(:segment)', 'Api\CreditContractsController::show/$1');
    $routes->put('(:segment)', 'Api\CreditContractsController::update/$1');

    // Contract lifecycle
    $routes->post('(:segment)/activate', 'Api\CreditContractsController::activate/$1');
    $routes->post('(:segment)/cancel', 'Api\CreditContractsController::cancel/$1');
    $routes->post('(:segment)/finalize', 'Api\CreditContractsController::finalize/$1');

    // Contract orders
    $routes->get('(:segment)/orders', 'Api\CreditContractsController::orders/$1');
    $routes->post('(:segment)/orders', 'Api\CreditContractsController::createOrder/$1');

    $routes->put(
        '(:segment)/orders/(:segment)',
        'Api\CreditContractsController::updateOrder/$1/$2'
    );

    $routes->post(
        '(:segment)/orders/(:segment)/cancel',
        'Api\CreditContractsController::cancelOrder/$1/$2'
    );

    // Payment information
    $routes->get(
        '(:segment)/payment-information',
        'Api\CreditContractsController::getPaymentInformation/$1'
    );

    $routes->put(
        '(:segment)/payment-information',
        'Api\CreditContractsController::savePaymentInformation/$1'
    );

    $routes->delete(
        '(:segment)/payment-information',
        'Api\CreditContractsController::deletePaymentInformation/$1'
    );

    // Withholding
    $routes->get(
        '(:segment)/withholding',
        'Api\CreditContractsController::getWithholding/$1'
    );

    $routes->put(
        '(:segment)/withholding',
        'Api\CreditContractsController::saveWithholding/$1'
    );

    // Financial report
    $routes->get(
        '(:segment)/financial-report',
        'Api\CreditContractsController::financialReport/$1'
    );

    // Final Sales Invoice
    $routes->get(
        '(:segment)/invoice',
        'Api\CreditContractsController::invoice/$1'
    );
});




/*
|--------------------------------------------------------------------------
| Standard Contract Orders API
|--------------------------------------------------------------------------
*/

$routes->group('api/contracts', ['namespace' => 'App\Controllers\Api'], static function ($routes) {

    // CORS preflight
    $routes->options('/', static function () {
        return service('response')->setStatusCode(204);
    });

    $routes->options('(:segment)', static function () {
        return service('response')->setStatusCode(204);
    });

    $routes->options('(:segment)/(:segment)', static function () {
        return service('response')->setStatusCode(204);
    });

    $routes->options('(:segment)/(:segment)/(:segment)', static function () {
        return service('response')->setStatusCode(204);
    });

    $routes->options('orders/(:segment)', static function () {
        return service('response')->setStatusCode(204);
    });

    // Contract list and CRUD
    $routes->get('/', 'ContractOrdersController::index');
    $routes->post('/', 'ContractOrdersController::create');
    $routes->get('(:segment)', 'ContractOrdersController::show/$1');
    $routes->put('(:segment)', 'ContractOrdersController::update/$1');

    // Contract lifecycle
    $routes->post('(:segment)/activate', 'ContractOrdersController::activate/$1');
    $routes->post('(:segment)/request-completion', 'ContractOrdersController::requestCompletion/$1');
    $routes->post('(:segment)/confirm-completion', 'ContractOrdersController::confirmCompletion/$1');
    $routes->post('(:segment)/cancel', 'ContractOrdersController::cancel/$1');

    // Contract orders
    $routes->get('all-orders', 'ContractOrdersController::allOrders');
    $routes->get('(:segment)/orders', 'ContractOrdersController::orders/$1');
    $routes->post('(:segment)/orders', 'ContractOrdersController::createOrder/$1');
    $routes->put('orders/(:segment)', 'ContractOrdersController::updateOrder/$1');
    $routes->post('orders/(:segment)/cancel', 'ContractOrdersController::cancelOrder/$1');

    // Progress and financial information
    $routes->get('(:segment)/progress', 'ContractOrdersController::progress/$1');
    $routes->get('(:segment)/financial-information', 'ContractOrdersController::financialInformation/$1');
    $routes->put('(:segment)/financial-information', 'ContractOrdersController::saveFinancialInformation/$1');
    $routes->get('(:segment)/financial-report', 'ContractOrdersController::financialReport/$1');

    // Contract attachments
    $routes->post('(:segment)/attachments', 'ContractOrdersController::uploadAttachment/$1');
    $routes->delete('(:segment)/attachments/(:segment)', 'ContractOrdersController::deleteAttachment/$1/$2');

    // Sales Invoice conversion handoff
    $routes->post('(:segment)/conversion/start', 'ContractOrdersController::startConversion/$1');
    $routes->post('(:segment)/conversion/complete', 'ContractOrdersController::markConverted/$1');
    $routes->post('(:segment)/conversion/failed', 'ContractOrdersController::conversionFailed/$1');
});
