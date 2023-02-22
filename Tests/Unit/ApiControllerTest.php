<?php

namespace Modules\AuthWithJWT\Tests\Unit;

use App\Models\User;
use Illuminate\Http\Response;
use Tests\TestCase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Request;
use Tymon\JWTAuth\Exceptions\JWTException;
use Modules\AuthWithJWT\Entities\Log;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class ApiControllerTest extends TestCase
{
    use  WithFaker, RefreshDatabase;
    /**
     * A basic unit test example.
     *
     * @return void
     */
    public function testExample()
    {
        $this->assertTrue(true);
    }

    /**
     * Test User Successfully register
     */
    public function testCanRegisterUser()
    {
        $name = $this->faker->name;
        $email = $this->faker->safeEmail;
        $password = $this->faker->password;
        $password_confirmation = $password;

        $response = $this->postJson(route('auth.register'), [
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $password
        ]);

        $response->assertStatus(Response::HTTP_OK)
            ->assertJsonStructure(['status','message', 'data']);
    }


    /**
     * Check User can register with invalid Data
     */
    public function testCannotRegisterUserWithInvalidData()
    {
        $response = $this->postJson(route('auth.register'), [
            'name' => '',
            'email' => 'invalid-email',
            'password' => 'short',
            'password_confirmation' => 'short'
        ]);


        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonValidationErrors(['name', 'email', 'password']);
    }


     /**
     * Test login with valid credentials
     *
     * @return void
     */
    public function testCanLoginWithValidCredentials()
    {
        // Create a user
        $user = User::factory()->create([
            'password' => bcrypt('password'),
        ]);

        // Make a login request with valid credentials
        $response = $this->postJson(route('auth.login'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        // Assert response
        $response->assertOk()
                 ->assertJson([
                     'status' => true,
                     'message' => 'Login Successfully',
                 ])
                 ->assertJsonStructure([
                     'token',
                 ]);
    }

    /**
     * Test login with invalid credentials
     *
     * @return void
     */
    public function testCannotLoginWithInvalidCredentials()
    {
        // Make a login request with invalid credentials
        $response = $this->postJson(route('auth.login'), [
            'email' => 'invalid-email',
            'password' => 'invalid-password',
        ]);

        // Assert response
        $response->assertStatus(Response::HTTP_OK)
                 ->assertJson([
                     'status' => false
                 ]);
    }

    /**
     * Check User can Logout
     */
    public function test_user_can_logout()
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('password')
        ]);
        $token = JWTAuth::fromUser($user);

        $response = $this->get(route('auth.logout', ['token' => $token]));

        $response->assertStatus(Response::HTTP_OK);
        $response->assertJson([
            'status' => true,
            'message' => 'User has been logged out'
        ]);

        $this->assertDatabaseHas('logs', [
            'user_id' => $user->id,
            'ip' => Request::ip(),
            'subject' => 'User Logged Out'
        ]);

        $this->assertFalse(JWTAuth::check($token));
    }

    /**
     * Check With Invalid Token
     */
    public function test_logout_fails_with_invalid_token()
    {
        $response = $this->get(route('auth.logout', ['token' => 'invalid_token']));

        $response->assertStatus(Response::HTTP_OK);
        $response->assertJson([
            'status' => 'Token is Invalid',
        ]);
    }


   /**
     * Test changing password with valid data and token.
     *
     * @return void
     */
    public function testChangePasswordWithValidDataAndToken()
    {
        $user = User::factory()->create([
            'password' => Hash::make('old_password')
        ]);

        $token = JWTAuth::fromUser($user);
        $newPassword = 'new_password';

        $response = $this->post(route('auth.change_password'), [
            'token' => $token,
            'old_password' => 'old_password',
            'password' => $newPassword,
            'password_confirmation' => $newPassword
        ]);

        $response->assertStatus(Response::HTTP_OK);
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'password' => Hash::check($newPassword, $user->password)
        ]);
    }

    /**
     * Test changing password with invalid data.
     *
     * @return void
     */
    public function testChangePasswordWithInvalidData()
    {
        $user = User::factory()->create([
            'password' => Hash::make('old_password')
        ]);
        $token = JWTAuth::fromUser($user);
        $newPassword = '123';

        $response = $this->post(route('auth.change_password'), [
            'token' => $token,
            'old_password' => 'old_password',
            'password' => $newPassword,
            'password_confirmation' => $newPassword
        ]);

        $response->assertStatus(Response::HTTP_OK);
    }

    /**
     * Test the "forgot password" feature with invalid email
     *
     * @return void
     */
    public function test_forgot_password_with_invalid_email()
    {
        Mail::fake();
        $response = $this->postJson(route('auth.forget_password'), ['email' => $this->faker->unique()->safeEmail]);
        $response->assertStatus(Response::HTTP_NOT_FOUND);
        $response->assertJson(['status' => false, 'message' => 'User Not Found']);
        Mail::assertNothingSent();
    }


    public function test_forgot_password_with_valid_email()
    {
        Mail::fake();

        $user = User::factory()->create();
        $response = $this->postJson(route('auth.forget_password'), ['email' => $user->email]);
        $response->assertStatus(Response::HTTP_UNAUTHORIZED);
    }

    /**
     * Test the "forgot password" feature with failed mail sending
     *
     * @return void
     */
    public function test_forgot_password_with_failed_mail_sending()
    {
        Mail::shouldReceive('to')->andThrow(new \Exception('Mail sending failed'));
        $user = User::factory()->create();
        $response = $this->postJson(route('auth.forget_password'), ['email' => $user->email]);
        $response->assertStatus(Response::HTTP_BAD_REQUEST);
        $response->assertJson(['status' => false, 'message' => 'Failed']);
    }

    /**
     * Test user logs API endpoint with valid token.
     *
     * @return void
     */
    public function testUserLogsApiWithValidToken()
    {
        $user = User::factory()->create();
        $logs = Log::factory()->count(5)->create(['user_id' => $user->id]);

        $token = JWTAuth::fromUser($user);

        $response = $this->post(route('auth.user_logs'), [
            'token' => $token,
        ]);

        $response->assertStatus(Response::HTTP_OK);

    }

    /**
     * Test user logs API endpoint with invalid token.
     *
     * @return void
     */
    public function testUserLogsApiWithInvalidToken()
    {
        $response = $this->post(route('auth.user_logs'), [
            'token' => $this->faker->text(30),
        ]);

        $response->assertStatus(Response::HTTP_OK);

            $response->assertJson([
            'status' => 'Token is Invalid'
        ]);
    }




}