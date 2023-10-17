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
use Shopware\Core\System\SystemConfig\SystemConfigService;

/** 
 * @Route(defaults={"_routeScope"={"storefront"}})
 */
class DiscordOAuthController extends StorefrontController {
    

    private GenericPageLoader $genericPageLoader;
    private HttpClientInterface $httpClient;
    private SalesChannelContextPersister $contextPersister;
    private AccountService $accountService;
    private SystemConfigService $systemConfigService;

    public function __construct(
        SystemConfigService $systemConfigService,
        GenericPageLoader $genericPageLoader,
        HttpClientInterface $httpClient,
        SalesChannelContextPersister $contextPersister,
        AccountService $accountService,
        EntityRepository $customerRepository
    ) {
        $this->systemConfigService = $systemConfigService;
        $this->genericPageLoader = $genericPageLoader;
        $this->httpClient = $httpClient;
        $this->contextPersister = $contextPersister;
        $this->accountService = $accountService;
        $this->customerRepository = $customerRepository; 

        // DISCORD STUFF
        $this->DISCORD_CLIENT_ID = $this->systemConfigService->get('MDDiscordLogin.config.discordAuthClientId');
        $this->DISCORD_CLIENT_SECRET = $this->systemConfigService->get('MDDiscordLogin.config.discordAuthSecret');        
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
        $this->base_url = $request->request->get('base');

        if($tokenType == null && $expires_in == null && $accessToken != null){
            $data = ($this->exchangeCode( $accessToken ));

            if( $data === false ){ 
                return new JsonResponse([
                    'error' => true, 
                    'msg' => 'The spirits were unable to conjure the Scrolls. Please try again later.'
                ]);
             }

            $tokenType = $data['token_type'];
            $accessToken = $data['access_token'];
            $expires_in = $data['expires_in'];
        }
        
        // fallback to prevent 500 stuff or something like that.
        if( $tokenType == null || $expires_in == null || $accessToken == null ){
            return new JsonResponse([
                'error' => true, 
                'msg' => 'The spirits were unable to conjure the new citizen. Please try again later.'
            ]);
        }



        try {
            $response = $this->httpClient->request('GET', 'https://discord.com/api/users/@me', [
                'headers' => [
                    'Authorization' => "$tokenType $accessToken",
                ],
            ]);
        } catch (TransportExceptionInterface $e) {
            return new JsonResponse([
                'error' => true, 
                'msg' => 'The stars have briefly veered off course. The spell to reach the realm of Discord has faltered. Please retry the spell of login, and the gateway shall unfold before thee.'
            ]);
        }
    
        $userData = $response->toArray();
        
        // Assuming $userData contains the Discord user data
        $discordId = $userData['id'];
        $email = $userData['email'];

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

            // Now, let's imbue their record with new essence
            $updateData = [
                'id' => $existingCustomer->getId(),
                'customFields' => [
                    'discord_data' => $userData,
                ]
            ];
            
            $this->customerRepository->upsert([$updateData], $context->getContext());
            
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
        $email = $userData['email'];

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
                        'countryId' => $this->systemConfigService->get('MDDiscordLogin.config.discordDefLang'),
                        'street' => 'unknown',
                        'zipcode' => 'unknown',
                        'city' => 'unknown',
                    ],
                    'customerNumber' => $userData['id'],
                    'customFields' => [
                        'discord_data' => $userData
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


     public function exchangeCode($code) {
        $response = $this->httpClient->request('POST', 'https://discord.com/api/v10/oauth2/token', [
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Authorization' => 'Basic ' . base64_encode("$this->DISCORD_CLIENT_ID:$this->DISCORD_CLIENT_SECRET"),
            ],
            'body' => [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $this->base_url . 'discord/auth'
            ],
        ]);

        try {
            $statusCode = $response->getStatusCode();
            if ($statusCode == 200) {
                return $response->toArray();  // This method will throw an exception if the JSON cannot be decoded
            } else {
                return false;
            }
        } catch (\Exception $e) {
            return false;
        }
    }
}