<?php

namespace Tests\Feature;

use Tests\Models\Address;
use Tests\Models\User;
use Tests\Models\Role;
use Tests\Models\Country;
use Tests\Models\Post;
use Tests\Models\UserProfile;
use Tests\TestCase;
use AlinO\MyOrm\Model;

class EloquentStyleRelationsTest extends TestCase
{
    protected static $createdModels = [];

    public static function tearDownAfterClass(): void
    {
        // Clean up all created models to avoid interference between test runs
        // This is a basic cleanup; more sophisticated cleanup might be needed for complex scenarios

        // Order of deletion matters due to foreign key constraints
        // Posts and UserProfiles depend on Users
        // Addresses depend on Users and Countries
        // User_Roles depend on Users and Roles

        // Delete from join tables first
        if (isset(static::$createdModels[User::class]) && isset(static::$createdModels[Role::class])) {
            $userIds = array_map(fn($u) => $u->id, static::$createdModels[User::class]);
            if ($userIds) {
                User::db()->where('user_id', $userIds, 'IN')->delete('user_roles');
            }
        }

        // Delete models that depend on others
        foreach ([Post::class, UserProfile::class, Address::class] as $modelClass) {
            if (isset(static::$createdModels[$modelClass])) {
                foreach (static::$createdModels[$modelClass] as $model) {
                    if ($model->id && $modelClass::find($model->id)) $model->delete();
                }
            }
        }

        // Delete primary models
        foreach ([User::class, Role::class, Country::class] as $modelClass) {
            if (isset(static::$createdModels[$modelClass])) {
                foreach (static::$createdModels[$modelClass] as $model) {
                    if ($model->id && $modelClass::find($model->id)) $model->delete();
                }
            }
        }
        static::$createdModels = [];
        parent::tearDownAfterClass();
    }

    protected static function addCreatedModel(Model $model)
    {
        static::$createdModels[get_class($model)][] = $model;
    }

    public function testBelongsToRelation()
    {
        $country = Country::create(['name' => 'Test Country for BelongsTo']);
        $this->assertNotNull($country, 'Country should be created');
        static::addCreatedModel($country);

        $user = User::create([
            'username' => 'user_for_belongsto_' . uniqid(),
            'email' => 'user_bt_' . uniqid() . '@example.com',
            'country_id' => $country->id()
        ]);
        $this->assertNotNull($user, 'User should be created');
        static::addCreatedModel($user);

        $address = Address::create([
            'user_id' => $user->id(),
            'address' => '123 BelongsTo St',
            'country_id' => $country->id() // Address also belongs to a country
        ]);
        $this->assertNotNull($address, 'Address should be created');
        static::addCreatedModel($address);

        // Test User->country()
        $userCountry = $user->country();
        $this->assertInstanceOf(Country::class, $userCountry);
        $this->assertEquals($country->id(), $userCountry->id());

        // Test Address->userEloquent()
        $addressUser = $address->userEloquent();
        $this->assertInstanceOf(User::class, $addressUser);
        $this->assertEquals($user->id(), $addressUser->id());
    }

    public function testHasOneRelation()
    {
        $user = User::create([
            'username' => 'user_for_hasone_' . uniqid(),
            'email' => 'user_ho_' . uniqid() . '@example.com'
        ]);
        $this->assertNotNull($user, 'User should be created');
        static::addCreatedModel($user);

        $profile = UserProfile::create([
            'user_id' => $user->id(),
            'bio' => 'This is a test bio.'
        ]);
        $this->assertNotNull($profile, 'UserProfile should be created');
        static::addCreatedModel($profile);

        // Test User->profile()
        $userProfile = $user->profile();
        $this->assertInstanceOf(UserProfile::class, $userProfile);
        $this->assertEquals($profile->id(), $userProfile->id());
        $this->assertEquals('This is a test bio.', $userProfile->bio);
    }

    public function testHasManyRelation()
    {
        $user = User::create([
            'username' => 'user_for_hasmany_' . uniqid(),
            'email' => 'user_hm_' . uniqid() . '@example.com'
        ]);
        $this->assertNotNull($user, 'User should be created');
        static::addCreatedModel($user);

        $post1 = Post::create(['user_id' => $user->id(), 'title' => 'Post 1 by User']);
        $post2 = Post::create(['user_id' => $user->id(), 'title' => 'Post 2 by User']);
        static::addCreatedModel($post1);
        static::addCreatedModel($post2);

        // Test User->posts()
        $posts = $user->posts();
        $this->assertCount(2, $posts);
        $this->assertInstanceOf(Post::class, $posts[0]);
        $this->assertContains($posts[0]->title, ['Post 1 by User', 'Post 2 by User']);
    }

    public function testBelongsToManyRelation()
    {
        $user = User::create([
            'username' => 'user_for_btm_' . uniqid(),
            'email' => 'user_btm_' . uniqid() . '@example.com'
        ]);
        $this->assertNotNull($user, 'User should be created');
        static::addCreatedModel($user);

        $roleAdmin = Role::create(['name' => 'Admin_' . uniqid()]);
        $roleEditor = Role::create(['name' => 'Editor_' . uniqid()]);
        static::addCreatedModel($roleAdmin);
        static::addCreatedModel($roleEditor);

        // Attach roles using the ORM's setRelated or direct pivot table insertion for setup
        // The ORM's __set magic method for relations handles this for HAS_MANY_THROUGH
        $user->roles = [$roleAdmin->id(), $roleEditor->id()]; // Uses existing static $relations logic for setup

        // Test User->eloquent_roles()
        $userRoles = $user->eloquent_roles();
        $this->assertCount(2, $userRoles);
        $this->assertInstanceOf(Role::class, $userRoles[0]);
        $roleNames = array_map(fn($r) => $r->name, $userRoles);
        $this->assertContains($roleAdmin->name, $roleNames);
        $this->assertContains($roleEditor->name, $roleNames);

        // Test Role->usersEloquent()
        $adminUsers = $roleAdmin->usersEloquent();
        $this->assertCount(1, $adminUsers);
        $this->assertInstanceOf(User::class, $adminUsers[0]);
        $this->assertEquals($user->id(), $adminUsers[0]->id());
    }

    public function testHasManyThroughRelation()
    {
        $country = Country::create(['name' => 'CountryForHMT_' . uniqid()]);
        $this->assertNotNull($country, 'Country should be created');
        static::addCreatedModel($country);

        $user1InCountry = User::create([
            'username' => 'user1_hmt_' . uniqid(),
            'email' => 'user1_hmt_' . uniqid() . '@example.com',
            'country_id' => $country->id()
        ]);
        static::addCreatedModel($user1InCountry);

        $user2InCountry = User::create([
            'username' => 'user2_hmt_' . uniqid(),
            'email' => 'user2_hmt_' . uniqid() . '@example.com',
            'country_id' => $country->id()
        ]);
        static::addCreatedModel($user2InCountry);

        $otherCountry = Country::create(['name' => 'OtherCountryHMT_' . uniqid()]);
        static::addCreatedModel($otherCountry);
        $userInOtherCountry = User::create([
            'username' => 'user_other_hmt_' . uniqid(),
            'email' => 'user_other_hmt_' . uniqid() . '@example.com',
            'country_id' => $otherCountry->id()
        ]);
        static::addCreatedModel($userInOtherCountry);

        $post1 = Post::create(['user_id' => $user1InCountry->id(), 'title' => 'Post by User1 in HMT Country']);
        $post2 = Post::create(['user_id' => $user2InCountry->id(), 'title' => 'Post by User2 in HMT Country']);
        $post3 = Post::create(['user_id' => $userInOtherCountry->id(), 'title' => 'Post by User in Other Country']);
        static::addCreatedModel($post1);
        static::addCreatedModel($post2);
        static::addCreatedModel($post3);

        // Test Country->posts()
        $countryPosts = $country->posts();
        $this->assertCount(2, $countryPosts, "Country should have 2 posts through its users.");
        $this->assertInstanceOf(Post::class, $countryPosts[0]);

        $postTitles = array_map(fn($p) => $p->title, $countryPosts);
        $this->assertContains($post1->title, $postTitles);
        $this->assertContains($post2->title, $postTitles);
        $this->assertNotContains($post3->title, $postTitles);
    }

    public function testMagicGetLoadsEloquentRelation()
    {
        $user = User::create([
            'username' => 'user_for_magicget_' . uniqid(),
            'email' => 'user_mg_' . uniqid() . '@example.com'
        ]);
        $this->assertNotNull($user, 'User should be created');
        static::addCreatedModel($user);

        Post::create(['user_id' => $user->id(), 'title' => 'Magic Post 1']);
        Post::create(['user_id' => $user->id(), 'title' => 'Magic Post 2']);

        // Access User->posts via magic __get
        $posts = $user->posts; // This should call the posts() method
        $this->assertCount(2, $posts);
        $this->assertInstanceOf(Post::class, $posts[0]);

        // Access again to test caching
        $user->relatedCache = []; // Clear cache to ensure method is called if not cached by __get
        $postsAgain = $user->posts;
        $this->assertCount(2, $postsAgain);

        // Test caching within __get itself
        $firstCallResult = $user->posts; // Calls method, caches
        $secondCallResult = $user->posts; // Should return from $this->relatedCache
        $this->assertSame($firstCallResult, $secondCallResult, "Consecutive magic __get calls for the same relation should return the same (cached) result.");
    }
}
