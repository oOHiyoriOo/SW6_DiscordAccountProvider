<?php declare(strict_types=1);

namespace MDDiscordLogin\Controller\Storefront;

use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Page\GenericPageLoader;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextPersister;
use Shopware\Core\Checkout\Customer\SalesChannel\AccountService;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Util\Random;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Checkout\Customer\CustomerEntity;

/** 
 * @Route(defaults={"_routeScope"={"storefront"}})
 */
class DiscordOAuthController extends StorefrontController {
    

    private GenericPageLoader $genericPageLoader;
    private HttpClientInterface $httpClient;
    private SalesChannelContextPersister $contextPersister;
    private AccountService $accountService;

    public function __construct(
        GenericPageLoader $genericPageLoader,
        HttpClientInterface $httpClient,
        SalesChannelContextPersister $contextPersister,
        AccountService $accountService,
        EntityRepository $customerRepository
    ) {
        $this->genericPageLoader = $genericPageLoader;
        $this->httpClient = $httpClient;
        $this->contextPersister = $contextPersister;
        $this->accountService = $accountService;
        $this->customerRepository = $customerRepository; 
    }

    /**
    * @Route("/discord/auth", name="frontend.discord.auth.get", methods={"GET"})
    */
    public function getAuth(Request $request, SalesChannelContext $context): Response
    {
        $page = $this->genericPageLoader->load($request, $context);

        return $this->renderStorefront('@Storefront/storefront/page/account/oauth/index.html.twig', [
            'page' => $page
        ]);
    }

    /**
    * @Route("/discord/auth", name="frontend.discord.auth.post", methods={"POST"}, defaults={"XmlHttpRequest"=true})
    */
    public function handleToken(Request $request, SalesChannelContext $context): Response {
        $tokenType = $request->request->get('tokenType');
        $accessToken = $request->request->get('accessToken');
        $expires_in = $request->request->get('expires_in');
        
        try {
            $response = $this->httpClient->request('GET', 'https://discord.com/api/users/@me', [
                'headers' => [
                    'Authorization' => "$tokenType $accessToken",
                ],
            ]);
        } catch (TransportExceptionInterface $e) {
            // The spell to reach the realm of Discord has faltered.
            // Let us send a whisper back to the seeker, guiding them back to the path.
            return new JsonResponse([
                'error' => true, 
                'msg' => 'The stars have briefly veered off course. The spell to reach the realm of Discord has faltered. Please retry the spell of login, and the gateway shall unfold before thee.'
            ]);
        }
    
        $userData = $response->toArray();
        
        // Assuming $userData contains the Discord user data
        $discordId = $userData['id'];
        $email = $userData['username'] . '@discord.com';  // Making up an email

        // Check if a customer with this discord-crafted email already exists
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('email', $email));
        $existingCustomer = $this->customerRepository->search($criteria, $context->getContext())->first();

        if ($existingCustomer) {
            // Customer exists, login as this customer
            $this->contextPersister->save(
                $context->getToken(),
                ['customerId' => $existingCustomer->getId()],
                $context->getSalesChannel()->getId()
            );
        } else {
            
            $newCustomer = $this->createCustomer($context, $userData);

            if ($newCustomer === null) {
                // The spirits failed to conjure the new customer, return an error message
                return new JsonResponse([
                    'error' => true,
                    'msg' => 'The spirits were unable to conjure the new citizen. Please try again later.'
                ]);
            }
        
            // The new customer was conjured successfully, proceed to login
            $this->contextPersister->save(
                $context->getToken(),
                ['customerId' => $newCustomer->getId()],
                $context->getSalesChannel()->getId()
            );
        }

        // Redirect to a page of your choice after login
        return new JsonResponse([
            'error' => false, 
            'msg' => 'redirect',
            'url' => $homePageUrl = $this->generateUrl('frontend.account.home.page')
        ]);
    }


    private function createCustomer(SalesChannelContext $context, array $userData): ?CustomerEntity {
        $email = $userData['username'] . '@discord.com';  // Making up an email

        $this->customerRepository->create(
            [
                [
                    'firstName' => $userData['username'],
                    'lastName' => 'Discord',
                    'email' => $email,
                    'password' => bin2hex(random_bytes(32)),
                    'salesChannelId' => $context->getSalesChannel()->getId(),
                    'defaultPaymentMethodId' => $context->getSalesChannel()->getPaymentMethodId(),
                    'groupId' => $context->getCurrentCustomerGroup()->getId(),
                    'defaultBillingAddress' => [
                        'firstName' => $userData['username'],
                        'lastName' => 'Discord',
                        'countryId' => '018b185586c6732eb260d010b805180d',
                        'street' => 'unknown',
                        'zipcode' => 'unknown',
                        'city' => 'unknown',
                    ],
                    'customerNumber' => $userData['id'],
                    'customFields' => [
                        'discord_avatar' => $userData['avatar'],
                        'discord_banner' => $userData['banner']
                    ]
                ]
            ],
            $context->getContext()
        );

        for ($i = 0; $i < 3; $i++) {  // Retry up to 3 times
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('email', $email));
            $customer = $this->customerRepository->search($criteria, $context->getContext())->first();
            if ($customer) {
                return $customer;  // Return the customer if found
            }
            usleep(100000);  // Wait for 100 ms before retrying
        }

        return null;
    }
}