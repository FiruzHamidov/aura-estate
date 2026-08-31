<?php

namespace Tests\Feature;

use Tests\TestCase;

class PushHealthFeatureTest extends TestCase
{
    public function test_it_reports_missing_firebase_credentials_without_exposing_the_path(): void
    {
        config()->set('services.firebase.credentials', '/missing/firebase-service-account.json');

        $response = $this->getJson('/api/push/health');

        $response
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson(['status' => 'not_configured']);
        $this->assertStringNotContainsString('firebase-service-account', $response->getContent());
    }

    public function test_it_reports_valid_firebase_service_account_as_ready(): void
    {
        $credentials = tempnam(sys_get_temp_dir(), 'aura-firebase-');
        file_put_contents($credentials, json_encode([
            'type' => 'service_account',
            'project_id' => 'aura-estate-e7696',
            'client_email' => 'firebase-adminsdk@example.iam.gserviceaccount.com',
            'private_key' => "-----BEGIN PRIVATE KEY-----\ntest\n-----END PRIVATE KEY-----\n",
        ], JSON_THROW_ON_ERROR));
        config()->set('services.firebase.credentials', $credentials);

        try {
            $this->getJson('/api/push/health')
                ->assertOk()
                ->assertExactJson(['status' => 'ready']);
        } finally {
            unlink($credentials);
        }
    }

    public function test_it_rejects_credentials_for_another_firebase_project(): void
    {
        $credentials = tempnam(sys_get_temp_dir(), 'aura-firebase-');
        file_put_contents($credentials, json_encode([
            'type' => 'service_account',
            'project_id' => 'another-project',
            'client_email' => 'firebase-adminsdk@example.iam.gserviceaccount.com',
            'private_key' => "-----BEGIN PRIVATE KEY-----\ntest\n-----END PRIVATE KEY-----\n",
        ], JSON_THROW_ON_ERROR));
        config()->set('services.firebase.credentials', $credentials);
        config()->set('services.firebase.project_id', 'aura-estate-e7696');

        try {
            $this->getJson('/api/push/health')
                ->assertOk()
                ->assertExactJson(['status' => 'not_configured']);
        } finally {
            unlink($credentials);
        }
    }
}
