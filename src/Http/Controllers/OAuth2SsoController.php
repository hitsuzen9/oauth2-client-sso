<?php

namespace Redmix0901\Oauth2Sso\Http\Controllers;

use Illuminate\Http\Request;
use Redmix0901\Oauth2Sso\SingleSignOn;
use App\Http\Controllers\Controller;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use Redmix0901\Oauth2Sso\Events\UserSsoLogin;
use Redmix0901\Oauth2Sso\Events\AccessTokenCreated;
use Illuminate\Contracts\Events\Dispatcher;

class OAuth2SsoController extends Controller
{
    /**
     * The event dispatcher instance.
     * 
     * @var \Illuminate\Contracts\Events\Dispatcher
     */
    protected $events;

    /**
     * @var Redmix0901\Oauth2Sso\SingleSignOn
     */
    protected $singleSignOn;

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct(SingleSignOn $singleSignOn, Dispatcher $events)
    {
        $this->events = $events;
        $this->singleSignOn = $singleSignOn;
    }
    
    /**
     * Redirect qua Auth Server để đăng nhập.
     *
     * @return void
     */
    public function login()
    {
        session()->put('callbackUrl', url()->previous());

        return $this->singleSignOn->getAuthRedirect();
    }

    /**
     * Redirect qua Auth Server để logout.
     *
     * @return void
     */
    public function logout()
    {
        $this->singleSignOn->deleteAccessTokenLocal();

        return $this->singleSignOn->getLogoutRedirect();
    }

    /**
     * Lấy token mới qua api, 
     * cho trường hợp token store trên cookie hết hạn 
     * hoặc có vấn đề gì không thể xác thực với Auth Server.
     *
     * @return void
     */
    public function issueTokenViaCookie(Request $request)
    {
        /** 
         * Kiểm tra access token từ session
         *
         * @var \League\OAuth2\Client\Token\AccessToken $accessToken 
         */
        $accessToken = $this->singleSignOn->getAccessTokenLocal();

        /**
         * Không có $accessToken tồn tại.
         */
        if (!$accessToken) {
            return response()
                ->json([
                    'error'   => true,
                    'data'    => null,
                    'message' => 'Unauthenticated.',
                ], 401);
        }

        try {

            /**
             * Xem $accessToken hết hạn sử dụng chưa.
             * Nếu hết hạn sẽ lấy $accessToken và 
             * refresh token mới bằng refresh token hiện tại.
             */
            $accessToken = $this->singleSignOn->refreshTokenIfExpired($accessToken);

            /**
             * Kiểm tra bằng cách lấy resource owner bằng $accessToken.
             */
            $resourceOwner = $this->singleSignOn->getUserByToken($accessToken);

        } catch (IdentityProviderException $e) {

            /**
             * Xóa $accessToken trên session nếu có lỗi.
             */
            $this->singleSignOn->deleteAccessTokenLocal();

            return response()
                ->json([
                    'error'   => true,
                    'data'    => null,
                    'message' => 'Unauthenticated.',
                ], 401);
        }

        $user = $resourceOwner->toArray();

        if (isset($user['message']) && $user['message'] == 'Unauthenticated.') {
            /**
             * Xóa $accessToken trên session nếu có lỗi.
             */
            $this->singleSignOn->deleteAccessTokenLocal();

            return response()
                ->json([
                    'error'   => true,
                    'data'    => null,
                    'message' => 'Unauthenticated.',
                ], 401);
        }
        
        $request->attributes->add(['oauth2_user' => $resourceOwner]);
        $config = $this->config->get('session');

        return response()
                ->json([
                    'error'   => false,
                    'message' => 'Succses.',
                ], 200)
                ->withCookie(
                    cookie(
                        SingleSignOn::cookie(),
                        $accessToken->getToken(),
                        $config['lifetime']
                    )
                );
    }

    /**
     * Callback trả về code từ Auth Server. Dùng để lấy AccessToken.
     * và lưu trữ AccessToken trên session
     *
     * @param Illuminate\Http\Request $request
     *
     * @return Redirect
     */
    public function callback(Request $request)
    {
        if (!$request->has('state') || $request->get('state') !== $request->session()->get('oauth2_auth_state')) {
            return response('Invalid state', 400);
        }

        $accessToken = $this->singleSignOn->getProvider()->getAccessToken('authorization_code', [
            'code' => $request->get('code'),
        ]);

        $this->singleSignOn->setAccessTokenLocal($accessToken);

        try {

            $resourceOwner = $this->singleSignOn->getUserByToken($accessToken);

            $this->events->dispatch(new UserSsoLogin(
                $this->singleSignOn->retrieveUser(
                    $resourceOwner->toArray()
                ),
                $accessToken
            ));

        } catch (IdentityProviderException $e) { }

        $this->events->dispatch(new AccessTokenCreated(
            $accessToken
        ));

        $callbackUrl = session()->get('callbackUrl');
        session()->remove('callbackUrl');

        return empty($callbackUrl) 
            ? redirect()->intended() : redirect()->to($callbackUrl);
    }
}