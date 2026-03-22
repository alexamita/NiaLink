<?php

namespace App\Http\Controllers;


/**
 * Base Controller
 * * This abstract class serves as the foundation for all NiaLink controllers.
 * It provides a central location to share common logic, such as custom
 * middleware application or authorization checks, across the entire API.
 */
abstract class Controller
{
    /**
     * Note for NiaLink: REMOVE LATER!!
     * In Laravel 11+, traits like 'AuthorizesRequests' and 'ValidatesRequests'
     * are automatically included in the framework's internal logic,
     * keeping this file clean and lightweight.
     */
}
