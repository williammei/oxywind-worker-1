<?php

namespace App\Controller\Pool;

use App\Entity\Autocomplete;
use App\Entity\Compile;
use App\Entity\Enum\RunStatus;
use App\Entity\Enum\Status;
use App\Entity\Run;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[Route('/pool/worker', name: 'app_pool_worker_')]
class WorkerController extends AbstractController implements TokenAuthenticatedController
{
    public function __construct(
        private ManagerRegistry $doctrine
    ) {
    }

    #[Route('/pull', name: 'pull', methods: ['POST'])]
    public function pull(Request $request): JsonResponse
    {
        $payload = $request->toArray();

        $repository = $this->doctrine->getRepository($payload['entity']);

        /** @var Autocomplete|Compile $entity */
        $entity = $repository->findOneBy(['uuid' => $payload['uuid']]);

        return $this->json([
            'data' => $entity,
        ], Response::HTTP_OK, [], [
            'groups' => ['compile:read', 'autocomplete:read'],
        ]);
    }

    #[Route('/push', name: 'push', methods: ['POST'])]
    public function push(Request $request, HttpClientInterface $githubClient): JsonResponse
    {
        $payload = $request->toArray();

        $repository = $this->doctrine->getRepository($payload['entity']);

        /** @var Autocomplete|Compile $entity */
        $entity = $repository->findOneBy(['uuid' => $payload['uuid']]);

        $run = new Run();
        $run->setId($payload['run_id']);
        
        if ($payload['run_status'] && $payload['run_status'] === RunStatus::Success) {
            $run->setStatus(RunStatus::Success);
            $entity->setStatus(Status::Done);
        } else {
            $run->setStatus(RunStatus::Failure);
            $entity->setStatus(Status::Failed);

            $repos = $this->getParameter('app.worker.repos');

            $endpoint = sprintf(
                '/repos/%s/%s/actions/runs/%s/jobs',
                $repos['owner'],
                $repos['repo'],
                $entity->getRun()->getId()
            );
            $response = $githubClient->request('GET', $endpoint);

            if (Response::HTTP_OK === $response->getStatusCode()) {
                $job = $response->toArray();
    
                $run->setJob($job);
            }
        }

        $entity->setRun($run);
        
        $this->doctrine->getManager()->persist($run);
        $this->doctrine->getManager()->flush();

        return $this->json([], Response::HTTP_OK);
    }
}
