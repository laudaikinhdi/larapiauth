<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use App\User;
use App\Notifications\RegisterActivate;
use App\Notifications\PasswordResetRequest;
use App\Notifications\PasswordResetSuccess;
use App\Notifications\PasswordChangeSuccess;
use App\PasswordReset;
use App\Http\AppResponse;
use Validator;
use App\Enums\RoleType;

class AuthController extends Controller
{
    /**
    * @OA\Post(
    *         path="/api/auth/register",
    *         tags={"Authentication"},
    *         summary="Register",
    *         description="Register a new user and send notification mail",
    *         operationId="register",
    *         @OA\Response(
    *             response=200,
    *             description="Successful operation"
    *         ),
    *         @OA\Response(
    *             response=422,
    *             description="Invalid input or email taken"
    *         ),
    *         @OA\Response(
    *             response=500,
    *             description="Server error"
    *         ),
    *         @OA\RequestBody(
    *             required=true,
    *             @OA\MediaType(
    *                 mediaType="application/x-www-form-urlencoded",
    *                 @OA\Schema(
    *                     type="object",
    *                     @OA\Property(
    *                         property="email",
    *                         description="Email",
    *                         type="string",
    *                     ),
    *                     @OA\Property(
    *                         property="password",
    *                         description="Password",
    *                         type="string",
    *                         format="password"
    *                     ),
    *                     @OA\Property(
    *                         property="password_confirmation",
    *                         description="Confirm password",
    *                         type="string",
    *                         format="password"
    *                     )
    *                 )
    *             )
    *         )
    * )
    */
    public function register(Request $request)
    {
        // Validate input data
        $validator = Validator::make($request->all(), [
            'email' => 'required|string|email|unique:users',
            'password' => 'required|string',
            'password_confirmation' => 'required|string|same:password'
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => AppResponse::STATUS_FAILURE, 'errors'=>$validator->errors()], AppResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Create user
        $user = new User([
            'email' => $request->email,
            'password' => bcrypt($request->password),
            'activation_token' => str_random(60)
        ]);
        $user->save();

        // Default role:
        $user->assignRole(RoleType::MEMBER);

        // Send email with activation link
        $user->notify(new RegisterActivate($user));

        return response()->json(['success' => AppResponse::STATUS_SUCCESS, 'data' => $user], AppResponse::HTTP_OK);
    }

    /**
    * @OA\Post(
    *         path="/api/auth/login",
    *         tags={"Authentication"},
    *         summary="Login",
    *         description="Login an user",
    *         operationId="login",
    *         @OA\Response(
    *             response=200,
    *             description="Successful operation"
    *         ),
    *         @OA\Response(
    *             response=422,
    *             description="Invalid input"
    *         ),
    *         @OA\Response(
    *             response=403,
    *             description="Wrong combination of email and password or email not verified"
    *         ),
    *         @OA\Response(
    *             response=500,
    *             description="Server error"
    *         ),
    *         @OA\RequestBody(
    *             required=true,
    *             @OA\MediaType(
    *                 mediaType="application/x-www-form-urlencoded",
    *                 @OA\Schema(
    *                     type="object",
    *                      @OA\Property(
    *                         property="email",
    *                         description="Email",
    *                         type="string",
    *                     ),
    *                     @OA\Property(
    *                         property="password",
    *                         description="Password",
    *                         type="string",
    *                     ),
    *                     @OA\Property(
    *                         property="remember_me",
    *                         description="Remember me",
    *                         type="boolean",
    *                     )
    *                 )
    *             )
    *         )
    * )
    */
    public function login(Request $request)
    {
        // Validate input data
        $validator = Validator::make($request->all(), [
            'email' => 'required|string|email',
            'password' => 'required|string',
            // 'remember_me' => 'boolean'
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => AppResponse::STATUS_FAILURE, 'errors'=>$validator->errors()], AppResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $credentials = request(['email', 'password']);
        $credentials['active'] = 1;
        $credentials['deleted_at'] = null;

        // Check the combination of email and password, also check for activation status
        if(!Auth::attempt($credentials)) {
            return response()->json(['success' => AppResponse::STATUS_FAILURE, 'message' => 'Wrong combination of email and password or email has not been verified'], AppResponse::HTTP_UNAUTHORIZED);
        }

        $user = $request->user();
        // Get access token
        $tokenResult = $user->createToken('Personal Access Token');
        $token = $tokenResult->token;
        // Add 1 week duration if user choose remember_me
        if ($request->remember_me)
            $token->expires_at = Carbon::now()->addWeeks(1);
        $token->save();

        // Prepare response data
        $data = [
            'access_token' => $tokenResult->accessToken,
            'token_type' => 'Bearer',
            'expires_at' => Carbon::parse(
                $tokenResult->token->expires_at
            )->toDateTimeString()
        ];

        return response()->json(['success' => AppResponse::STATUS_SUCCESS, 'data' => $data], AppResponse::HTTP_OK);
    }

    /**
    * @OA\Get(
    *         path="/api/auth/logout",
    *         tags={"Authentication"},
    *         summary="Logout",
    *         description="Logout an user",
    *         operationId="logout",
    *         security={
    *           {"bearerAuth": {}}
    *         },
    *         @OA\Response(
    *             response=200,
    *             description="Successful operation"
    *         ),
    *         @OA\Response(
    *             response=500,
    *             description="Server error"
    *         ),
    * )
    */
    public function logout(Request $request)
    {
        // We delete the token completely instead of revoking it (invalidating it)
        $request->user()->token()->delete();
        //$request->user()->token()->revoke();

        return response()->json(['success' => AppResponse::STATUS_SUCCESS, 'message' => 'Successfully logged out'], AppResponse::HTTP_OK);
    }

    /**
    * @OA\Get(
    *         path="/api/auth/getUser",
    *         tags={"Authentication"},
    *         summary="Get user",
    *         description="Retrieve information from current user",
    *         operationId="getUser",
    *         security={
    *           {"bearerAuth": {}}
    *         },
    *         @OA\Response(
    *             response=200,
    *             description="Successful operation"
    *         ),
    *         @OA\Response(
    *             response=500,
    *             description="Server error"
    *         ),
    * )
    */
    public function getUser(Request $request)
    {
        $result = $request->user();
        $result['roles'] = $result->getRoleNames();
        return response()->json(['success' => AppResponse::STATUS_SUCCESS, 'data' => $request->user()], AppResponse::HTTP_OK);
    }

    /**
    * @OA\Get(
    *         path="/api/auth/register/activate/{token}",
    *         tags={"Authentication"},
    *         summary="Activate user",
    *         description="Activate an registered user",
    *         operationId="activateUser",
    *         @OA\Parameter(
    *             name="token",
    *             in="path",
    *             description="User activating token (should be included in the verification mail)",
    *             required=true,
    *             @OA\Schema(
    *                 type="string",
    *             )
    *         ),
    *         @OA\Response(
    *             response=200,
    *             description="Successful operation"
    *         ),
    *         @OA\Response(
    *             response=400,
    *             description="Invalid token"
    *         ),
    *         @OA\Response(
    *             response=500,
    *             description="Server error"
    *         ),
    * )
    */
    public function activate($token)
    {
        $user = User::where('activation_token', $token)->first();
        // If the token is not existing, throw error
        if (!$user) {
            return response()->json(['success' => AppResponse::STATUS_FAILURE, 'message' => 'This activation token is invalid'], AppResponse::HTTP_BAD_REQUEST);
        }
        // Update activation info
        $user->active = true;
        $user->activation_token = '';
        $user->email_verified_at = Carbon::now();
        $user->save();

        return response()->json(['success' => AppResponse::STATUS_SUCCESS, 'data' => $user], AppResponse::HTTP_OK);
    }

    /**
    * @OA\Post(
    *         path="/api/auth/password/token/create",
    *         tags={"Authentication"},
    *         summary="Request resetting password",
    *         description="Generate password reset token and send that token to user through mail",
    *         operationId="createPasswordResetToken",
    *         @OA\Response(
    *             response=200,
    *             description="Successful operation"
    *         ),
    *         @OA\Response(
    *             response=400,
    *             description="Email not existing"
    *         ),
    *         @OA\Response(
    *             response=422,
    *             description="Invalid input"
    *         ),
    *         @OA\Response(
    *             response=500,
    *             description="Server error"
    *         ),
    *         @OA\RequestBody(
    *             required=true,
    *             @OA\MediaType(
    *                 mediaType="application/x-www-form-urlencoded",
    *                 @OA\Schema(
    *                     type="object",
    *                     @OA\Property(
    *                         property="email",
    *                         description="Email",
    *                         type="string",
    *                     ),
    *                 )
    *             )
    *         )
    * )
    */
    public function createPasswordResetToken(Request $request)
    {
        // Validate input data
        $validator = Validator::make($request->all(), [
            'email' => 'required|string|email',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => AppResponse::STATUS_FAILURE, 'errors'=>$validator->errors()], AppResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user = User::where('email', $request->email)->first();
        // If the email is not existing, throw error
        if (!$user) {
            return response()->json(['success' => AppResponse::STATUS_FAILURE, 'message' => "We can't find a user with that e-mail address"], AppResponse::HTTP_BAD_REQUEST);
        }
        // Create or update token
        $passwordReset = PasswordReset::updateOrCreate(
            ['email' => $user->email],
            [
                'email' => $user->email,
                'token' => str_random(60)
             ]
        );
        if ($user && $passwordReset) {
            $user->notify(new PasswordResetRequest($passwordReset->token));
        }

        return response()->json(['success' => AppResponse::STATUS_SUCCESS, 'message' => "We have e-mailed your password reset link"], AppResponse::HTTP_OK);
    }

    /**
    * @OA\Get(
    *         path="/api/auth/password/token/find/{token}",
    *         tags={"Authentication"},
    *         summary="Verify reset password token",
    *         description="Verify the reset password token and make sure it is existing and still valid",
    *         operationId="findPasswordResetToken",
    *         @OA\Parameter(
    *             name="token",
    *             in="path",
    *             description="Password reset token (should be included in the notification mail)",
    *             required=true,
    *             @OA\Schema(
    *                 type="string",
    *             )
    *         ),
    *         @OA\Response(
    *             response=200,
    *             description="Successful operation"
    *         ),
    *         @OA\Response(
    *             response=400,
    *             description="Invalid token"
    *         ),
    *         @OA\Response(
    *             response=500,
    *             description="Server error"
    *         ),
    * )
    */
    public function findPasswordResetToken($token)
    {
        // Make sure the password reset token is findable, otherwise throw error
        $passwordReset = PasswordReset::where('token', $token)->first();
        if (!$passwordReset) {
            return response()->json(['success' => AppResponse::STATUS_FAILURE, 'message' => "This password reset token is invalid"], AppResponse::HTTP_BAD_REQUEST);
        }

        if (Carbon::parse($passwordReset->updated_at)->addMinutes(720)->isPast()) {
            $passwordReset->delete();
            return response()->json(['success' => AppResponse::STATUS_FAILURE, 'message' => "This password reset token is invalid"], AppResponse::HTTP_BAD_REQUEST);
        }

        return response()->json(['success' => AppResponse::STATUS_SUCCESS, 'data' => $passwordReset], AppResponse::HTTP_OK);
    }

    /**
    * @OA\Post(
    *         path="/api/auth/password/reset",
    *         tags={"Authentication"},
    *         summary="Reset password",
    *         description="Set new password for the user",
    *         operationId="resetPassword",
    *         @OA\Response(
    *             response=200,
    *             description="Successful operation"
    *         ),
    *         @OA\Response(
    *             response=400,
    *             description="Password reset token invalid or email not existing"
    *         ),
    *         @OA\Response(
    *             response=422,
    *             description="Invalid input"
    *         ),
    *         @OA\Response(
    *             response=500,
    *             description="Server error"
    *         ),
    *         @OA\RequestBody(
    *             required=true,
    *             @OA\MediaType(
    *                 mediaType="application/x-www-form-urlencoded",
    *                 @OA\Schema(
    *                     type="object",
    *                     @OA\Property(
    *                         property="email",
    *                         description="Email",
    *                         type="string",
    *                     ),
    *                     @OA\Property(
    *                         property="password",
    *                         description="Password",
    *                         type="string",
    *                     ),
    *                     @OA\Property(
    *                         property="password_confirmation",
    *                         description="Confirm password",
    *                         type="string",
    *                     ),
    *                     @OA\Property(
    *                         property="token",
    *                         description="Password reset token",
    *                         type="string",
    *                     ),
    *                 )
    *             )
    *         )
    * )
    */
    public function resetPassword(Request $request)
    {
        // Validate input data
        $validator = Validator::make($request->all(), [
            'email' => 'required|string|email',
            'password' => 'required|string',
            'password_confirmation' => 'required|string|same:password',
            'token' => 'required|string'
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => AppResponse::STATUS_FAILURE, 'errors'=>$validator->errors()], AppResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $passwordReset = PasswordReset::where([
            ['token', $request->token],
            ['email', $request->email]
        ])->first();
        if (!$passwordReset) {
            return response()->json(['success' => AppResponse::STATUS_FAILURE, 'message' => "This password reset token is invalid"], AppResponse::HTTP_BAD_REQUEST);
        }

        $user = User::where('email', $passwordReset->email)->first();
        if (!$user) {
            return response()->json(['success' => AppResponse::STATUS_FAILURE, 'message' => "We can't find a user with that e-mail address"], AppResponse::HTTP_BAD_REQUEST);
        }

        // Save new password
        $user->password = bcrypt($request->password);
        $user->save();
        // Delete password reset token
        $passwordReset->delete();
        // Send notification email
        $user->notify(new PasswordResetSuccess($passwordReset));

        return response()->json(['success' => AppResponse::STATUS_SUCCESS, 'data' => $user], AppResponse::HTTP_OK);
    }

    /**
    * @OA\Post(
    *         path="/api/auth/password/change",
    *         tags={"Authentication"},
    *         summary="Change password",
    *         description="Change an user's password (requires current password) and send notification mail",
    *         operationId="changePassword",
    *         security={
    *           {"bearerAuth": {}}
    *         },
    *         @OA\Response(
    *             response=200,
    *             description="Successful operation"
    *         ),
    *         @OA\Response(
    *             response=422,
    *             description="Invalid input"
    *         ),
    *         @OA\Response(
    *             response=403,
    *             description="Wrong combination of email and password or email not verified"
    *         ),
    *         @OA\Response(
    *             response=500,
    *             description="Server error"
    *         ),
    *         @OA\RequestBody(
    *             required=true,
    *             @OA\MediaType(
    *                 mediaType="application/x-www-form-urlencoded",
    *                 @OA\Schema(
    *                     type="object",
    *                      @OA\Property(
    *                         property="password",
    *                         description="Password",
    *                         type="string",
    *                     ),
    *                     @OA\Property(
    *                         property="new_password",
    *                         description="New password",
    *                         type="string",
    *                     ),
    *                     @OA\Property(
    *                         property="new_password_confirmation",
    *                         description="Confirm new password",
    *                         type="string",
    *                     ),
    *                 )
    *             )
    *         )
    * )
    */
    public function changePassword(Request $request)
    {
        $user = $request->user();

        $email = $user->email;
        // Validate input data
        $validator = Validator::make($request->all(), [
            'password' => 'required|string',
            'new_password' => 'required|string|confirmed'
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => AppResponse::STATUS_FAILURE, 'errors'=>$validator->errors()], AppResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Check if the combination of email and password is correct, if it is then proceed, if no, throw error
        $credentials = request(['password']);
        $credentials['email'] = $email;
        $credentials['active'] = 1;
        $credentials['deleted_at'] = null;

        // Check the combination of email and password, also check for activation status
        if(!Auth::guard('web')->attempt($credentials)) {
            return response()->json(['success' => AppResponse::STATUS_FAILURE, 'message' => 'Wrong combination of email and password or email has not been verified'], AppResponse::HTTP_UNAUTHORIZED);
        }

        // Save new password
        $user->password = bcrypt($request->new_password);
        $user->save();

        // Send notification email
        $user->notify(new PasswordChangeSuccess());

        return response()->json(['success' => AppResponse::STATUS_SUCCESS, 'data' => $user], AppResponse::HTTP_OK);
    }
}
