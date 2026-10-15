<?php

declare(strict_types=1);

namespace Everysize\Checkout\Controller\Api;

use Everysize\Checkout\Service\ClientService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['api']])]
class EverysizeApiController extends AbstractController
{
    private ClientService $clientService;

    public function __construct(ClientService $clientService)
    {
        $this->clientService = $clientService;
    }

    #[Route(path: '/api/_action/everysize/checkApi', name: 'api.everysize.api.check', methods: ['POST'])]
    public function checkApi(Request $request): JsonResponse
    {
        $clientId = $request->get('clientId');
        $clientSecret = $request->get('clientSecret');

        $success = false;
        if (!empty($clientId) && !empty($clientSecret) && $this->clientService->getAccessToken([
            'clientId' => $clientId,
            'clientSecret' => $clientSecret,
        ])) {
            $success = true;
        }

        return new JsonResponse(['success' => $success]);
    }
}
