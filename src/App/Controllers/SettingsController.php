<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class SettingsController extends BaseController
{
    /**
     * Show settings index
     */
    public function index(Request $request, Response $response): Response
    {
        $user = $this->getUser($request);
        
        return $this->render($response, 'settings/index.html.twig', [
            'user' => $user,
        ]);
    }
}












