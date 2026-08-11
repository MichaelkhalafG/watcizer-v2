<?php

namespace App\Exceptions;

use Throwable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;

class Handler extends ExceptionHandler
{
    /**
     * A list of exception types with their corresponding custom log levels.
     *
     * @var array<class-string<\Throwable>, \Psr\Log\LogLevel::*>
     */
    protected $levels = [
        //
    ];

    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<\Throwable>>
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }

    public function render($request, Throwable $exception)
    {
        // An admin image upload that couldn't be processed → graceful redirect
        // back to the form with the input preserved and a friendly message,
        // instead of a raw 500 that loses the data entry team's work.
        if ($exception instanceof ImageProcessingException) {
            return back()->withInput()->withErrors([$exception->field => $exception->getMessage()]);
        }

        if ($exception instanceof AuthorizationException) {
            return response()->view('errors.403', [], 403);
        }

        // Render the status-specific error view when one exists, instead of
        // forcing EVERY HttpException to the 404 page (the old behaviour hid the
        // real status — a 419/405/500 all showed "404"). Falls through to the
        // framework otherwise, which also picks up errors/419 + errors/500.
        if ($this->isHttpException($exception)) {
            $status = $exception->getStatusCode();
            if (view()->exists("errors.{$status}")) {
                return response()->view("errors.{$status}", ['exception' => $exception], $status);
            }
        }

        return parent::render($request, $exception);
    }
}
