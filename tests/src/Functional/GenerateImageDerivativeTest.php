<?php

namespace Drupal\Tests\islandora_image\Functional;

use Drupal\Tests\islandora\Functional\IslandoraFunctionalTestBase;

/**
 * Tests the GenerateImageDerivative action.
 *
 * @group islandora_image
 */
class GenerateImageDerivativeTest extends IslandoraFunctionalTestBase {

  protected static $modules = ['context_ui', 'islandora_image'];

  protected static $configSchemaCheckerExclusions = [
    'context.context.tiff_image',
    'context.context.web_image',
  ];

  /**
   * @covers \Drupal\islandora_image\Plugin\Action\GenerateImageDerivative::defaultConfiguration
   * @covers \Drupal\islandora_image\Plugin\Action\GenerateImageDerivative::buildConfigurationForm
   * @covers \Drupal\islandora_image\Plugin\Action\GenerateImageDerivative::submitConfigurationForm
   * @covers \Drupal\islandora_image\Plugin\Action\GenerateImageDerivative::execute
   */
  public function testGenerateImageDerivativeFromScratch() {

    // Delete the context entities provided by the module.
    // We're building our own to test the form.
    $this->container->get('entity_type.manager')->getStorage('context')->load('tiff_image')->delete();
    $this->container->get('entity_type.manager')->getStorage('context')->load('web_image')->delete();

    // Create a test user.
    $account = $this->drupalCreateUser([
      'bypass node access',
      'administer contexts',
      'administer actions',
      'view media',
      'create media',
      'update media',
    ]);
    $this->drupalLogin($account);

    // Create an action to generate a jpeg thumbnail.
    $this->drupalGet('admin/config/system/actions');
    $this->getSession()->getPage()->findById("edit-action")->selectOption("Generate an image derivative...");
    $this->getSession()->getPage()->pressButton(t('Create'));
    $this->assertSession()->statusCodeEquals(200);

    $this->getSession()->getPage()->fillField('edit-label', "Generate test derivative");
    $this->getSession()->getPage()->fillField('edit-id', "generate_test_derivative");
    $this->getSession()->getPage()->fillField('edit-queue', "generate-test-derivative");
    $this->getSession()->getPage()->findById("edit-source")->selectOption('field_media');
    $this->getSession()->getPage()->findById("edit-destination")->selectOption('field_media');
    $this->getSession()->getPage()->findById("edit-bundle")->selectOption('tn');
    $this->getSession()->getPage()->fillField('edit-mimetype', "image/jpeg");
    $this->getSession()->getPage()->fillField('edit-args', "-thumbnail 20x20");
    $this->getSession()->getPage()->pressButton(t('Save'));
    $this->assertSession()->statusCodeEquals(200);

    // Create a context and add the action as a derivative reaction.
    $this->createContext('Test', 'test');
    $this->drupalGet("admin/structure/context/test/condition/add/is_referenced_media");
    $this->getSession()->getPage()->findById("edit-conditions-is-referenced-media-field")->selectOption('test_type_with_reference|field_media');
    $this->getSession()->getPage()->pressButton('Save and continue');
    $this->addPresetReaction('test', 'derivative', "generate_test_derivative");
    $this->assertSession()->statusCodeEquals(200);

    // Create a new media.
    $urls = $this->createThumbnailWithFile();

    // Media is not referenced, so derivatives should not fire.
    $this->checkNoMessage();

    // Create a new node without referencing a media and confirm derivatives
    // do not fire.
    $this->postNodeAddForm('test_type_with_reference', ['title[0][value]' => 'Test Node'], 'Save');
    $this->checkNoMessage();

    // Create a new node that does reference media and confirm derivatives
    // do fire.
    $this->postNodeAddForm(
      'test_type_with_reference',
      [
        'title[0][value]' => 'Test Node 2',
        'field_media[0][target_id]' => 'Test Media',
      ],
      'Save'
    );
    $this->checkMessage();

    // Stash the node's url.
    $url = $this->getUrl();

    // Edit the node but not the media and confirm derivatives do not fire.
    $this->postEntityEditForm($url, ['title[0][value]' => 'Test Node Changed'], 'Save');
    $this->checkNoMessage();

    // Edit the Media now that it's referenced.
    $this->postEntityEditForm($urls['media'], ['field_image[0][alt]' => 'alt text changed'], 'Save');
    $this->checkMessage();
  }

  /**
   * Asserts that no message was delivered.
   */
  protected function checkNoMessage() {
    // Verify no message is sent.
    $stomp = $this->container->get('islandora.stomp');
    try {
      $stomp->subscribe('generate-test-derivative');
      $this->assertTrue(!$stomp->read());
      $stomp->unsubscribe();
    }
    catch (StompException $e) {
      $this->assertTrue(FALSE, "There was an error connecting to the stomp broker");
    }
  }

  /**
   * Asserts a derivative event was delivered.
   */
  protected function checkMessage() {
    // Verify message is sent.
    $stomp = $this->container->get('islandora.stomp');
    try {
      $stomp->subscribe('generate-test-derivative');
      while ($msg = $stomp->read()) {
        $headers = $msg->getHeaders();
        $this->assertTrue(
          isset($headers['Authorization']),
          "Authorization header must be set"
        );
        $matches = [];
        $this->assertTrue(
          preg_match('/^Bearer (.*)/', $headers['Authorization'], $matches),
          "Authorization header must be a bearer token"
        );
        $this->assertTrue(
          count($matches) == 2 && !empty($matches[1]),
          "Bearer token must not be empty"
        );

        $body = $msg->getBody();
        $body = json_decode($body, TRUE);

        $type = $body['type'];
        $this->assertTrue($type == 'Activity', "Expected 'Activity', received $type");

        $summary = $body['summary'];
        $this->assertTrue($summary == 'Generate Derivative', "Expected 'Generate Derivative', received $summary");

        $content = $body['attachment']['content'];
        $this->assertTrue($content['source'] == 'field_media', "Expected source 'field_media', received {$content['source']}");
        $this->assertTrue($content['destination'] == 'field_media', "Expected destination 'field_media', received {$content['destination']}");
        $this->assertTrue($content['bundle'] == 'tn', "Expected bundle 'tn', received {$content['bundle']}");
        $this->assertTrue($content['mimetype'] == 'image/jpeg', "Expected bundle 'image/jpeg', received {$content['mimetype']}");
        $this->assertTrue($content['args'] == '-thumbnail 20x20', "Expected bundle '-thumbnail 20x20', received {$content['args']}");
      }
      $stomp->unsubscribe();
    }
    catch (StompException $e) {
      $this->assertTrue(FALSE, "There was an error connecting to the stomp broker");
    }
  }

}
