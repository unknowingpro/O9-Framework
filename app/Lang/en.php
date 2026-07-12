<?php
declare(strict_types=1);

// English — base/fallback locale. Add keys here first, then mirror in every
// other app/Lang/*.php file. :param tokens are substituted by Lang::get();
// %count% (and other %param%) tokens are substituted by Lang::choice().
return [
    'welcome'            => 'Welcome to :app!',
    'error_generic'      => 'Something went wrong. Please try again.',
    'not_found'          => 'Not found.',
    'back'               => 'Back',
    'cancel'             => 'Cancel',
    'save'               => 'Save',
    'delete'             => 'Delete',
    'yes'                => 'Yes',
    'no'                 => 'No',
    'confirm'            => 'Are you sure?',
    'loading'            => 'Loading…',
    'success'            => 'Success!',
    'required_field'     => 'This field is required.',
    'validation_failed'  => 'Please check the form and try again.',
    'unauthorized'       => 'You must sign in to continue.',
    'forbidden'          => 'You do not have permission to do that.',
    'server_error'       => 'A server error occurred.',
    'search_placeholder' => 'Search…',
    'no_results'         => 'No results for ":query".',
    'language_changed'   => 'Language changed to :language.',
    'items_count'        => 'one::%count% item|other::%count% items',
];
