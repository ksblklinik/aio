<?php

declare(strict_types=1);

// Application-level Global Helpers for Shared Modules (Level 2)

if (!function_exists('contacts')) {
    /**
     * Helper global untuk Universal Contact & Role Registry
     */
    function contacts(): \App\Modules\Contact\Contracts\ContactRegistryInterface
    {
        return app(\App\Modules\Contact\Contracts\ContactRegistryInterface::class);
    }
}

if (!function_exists('finance')) {
    /**
     * Helper global untuk Universal Finance & Cash Flow Engine
     */
    function finance(): \App\Modules\Finance\Contracts\FinanceRegistryInterface
    {
        return app(\App\Modules\Finance\Contracts\FinanceRegistryInterface::class);
    }
}

if (!function_exists('items')) {
    /**
     * Helper global untuk Universal Items, Catalog & Inventory Engine
     */
    function items(): \App\Modules\Inventory\Contracts\InventoryRegistryInterface
    {
        return app(\App\Modules\Inventory\Contracts\InventoryRegistryInterface::class);
    }
}

if (!function_exists('inventory')) {
    /**
     * Alias helper global untuk Universal Items, Catalog & Inventory Engine
     */
    function inventory(): \App\Modules\Inventory\Contracts\InventoryRegistryInterface
    {
        return app(\App\Modules\Inventory\Contracts\InventoryRegistryInterface::class);
    }
}

if (!function_exists('billing')) {
    /**
     * Helper global untuk Universal Invoicing, Billing & Document Engine
     */
    function billing(): \App\Modules\Billing\Contracts\BillingRegistryInterface
    {
        return app(\App\Modules\Billing\Contracts\BillingRegistryInterface::class);
    }
}

if (!function_exists('invoices')) {
    /**
     * Alias helper global untuk Universal Invoicing, Billing & Document Engine
     */
    function invoices(): \App\Modules\Billing\Contracts\BillingRegistryInterface
    {
        return app(\App\Modules\Billing\Contracts\BillingRegistryInterface::class);
    }
}

if (!function_exists('facilities')) {
    /**
     * Helper global untuk Universal Facilities, Spaces & Locations Registry
     */
    function facilities(): \App\Modules\Facility\Contracts\FacilityRegistryInterface
    {
        return app(\App\Modules\Facility\Contracts\FacilityRegistryInterface::class);
    }
}

if (!function_exists('schedule')) {
    /**
     * Helper global untuk Universal Scheduling, Appointments & Human Queue Engine
     */
    function schedule(): \App\Modules\Schedule\Contracts\ScheduleRegistryInterface
    {
        return app(\App\Modules\Schedule\Contracts\ScheduleRegistryInterface::class);
    }
}

if (!function_exists('appointments')) {
    /**
     * Alias helper global untuk Universal Appointment Booking Engine
     */
    function appointments(): \App\Modules\Schedule\Contracts\ScheduleRegistryInterface
    {
        return app(\App\Modules\Schedule\Contracts\ScheduleRegistryInterface::class);
    }
}

if (!function_exists('humanQueue')) {
    /**
     * Alias helper global untuk Universal Human Queue Engine
     */
    function humanQueue(): \App\Modules\Schedule\Contracts\ScheduleRegistryInterface
    {
        return app(\App\Modules\Schedule\Contracts\ScheduleRegistryInterface::class);
    }
}

if (!function_exists('tariff')) {
    /**
     * Helper global untuk Universal Tariffs, Multi-Payer Price Books & Split Fee Engine
     */
    function tariff(): \App\Modules\Tariff\Contracts\TariffRegistryInterface
    {
        return app(\App\Modules\Tariff\Contracts\TariffRegistryInterface::class);
    }
}

if (!function_exists('priceBooks')) {
    /**
     * Alias helper global untuk Universal Price Books & Tariffs Engine
     */
    function priceBooks(): \App\Modules\Tariff\Contracts\TariffRegistryInterface
    {
        return app(\App\Modules\Tariff\Contracts\TariffRegistryInterface::class);
    }
}

if (!function_exists('terminology')) {
    /**
     * Helper global untuk Universal Semantic Coding & Terminology Registry
     */
    function terminology(): \App\Modules\Terminology\Contracts\TerminologyRegistryInterface
    {
        return app(\App\Modules\Terminology\Contracts\TerminologyRegistryInterface::class);
    }
}

if (!function_exists('documentSeal')) {
    /**
     * Helper global untuk Universal Document Cryptographic Seal & Verification Portal
     */
    function documentSeal(): \App\Modules\Seal\Contracts\DocumentSealRegistryInterface
    {
        return app(\App\Modules\Seal\Contracts\DocumentSealRegistryInterface::class);
    }
}
