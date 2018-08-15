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

  /**
   * Node to hold the media.
   *
   * @var \Drupal\node\NodeInterface
   */
  protected $node;

  /**
   * Term to belong to the node.
   *
   * @var \Drupal\taxonomy\TermInterface
   */
  protected $imageTerm;

  /**
   * Term to belong to the source media.
   *
   * @var \Drupal\taxonomy\TermInterface
   */
  protected $preservationMasterTerm;

  /**
   * Term to belong to the derivative media.
   *
   * @var \Drupal\taxonomy\TermInterface
   */
  protected $serviceFileTerm;

  /**
   * {@inheritdoc}
   */
  public function setUp() {
    parent::setUp();

    // Create a test user.
    $account = $this->drupalCreateUser();
    $this->drupalLogin($account);

    // 'Image' tag.
    $this->imageTerm = $this->container->get('entity_type.manager')->getStorage('taxonomy_term')->create([
      'name' => 'Image',
      'vid' => $this->testVocabulary->id(),
      'field_external_uri' => [['uri' => "http://purl.org/coar/resource_type/c_c513"]],
    ]);
    $this->imageTerm->save();

    // 'Preservation Master' tag.
    $this->preservationMasterTerm = $this->container->get('entity_type.manager')->getStorage('taxonomy_term')->create([
      'name' => 'Preservation Master',
      'vid' => $this->testVocabulary->id(),
      'field_external_uri' => [['uri' => "http://pcdm.org/use#PreservationMasterFile"]],
    ]);
    $this->preservationMasterTerm->save();

    // 'Preservation Master' tag.
    $this->serviceFileTerm = $this->container->get('entity_type.manager')->getStorage('taxonomy_term')->create([
      'name' => 'Service File',
      'vid' => $this->testVocabulary->id(),
      'field_external_uri' => [['uri' => "http://pcdm.org/use#ServiceFile"]],
    ]);
    $this->serviceFileTerm->save();

    // Node to be referenced via media_of.
    $this->node = $this->container->get('entity_type.manager')->getStorage('node')->create([
      'type' => $this->testType->id(),
      'title' => 'Test Node',
      'field_tags' => [$this->imageTerm->id()],
    ]);
    $this->node->save();
  }

  /**
   * @covers \Drupal\islandora_image\Plugin\Action\GenerateImageDerivative::defaultConfiguration
   * @covers \Drupal\islandora_image\Plugin\Action\GenerateImageDerivative::buildConfigurationForm
   * @covers \Drupal\islandora_image\Plugin\Action\GenerateImageDerivative::submitConfigurationForm
   * @covers \Drupal\islandora_image\Plugin\Action\GenerateImageDerivative::execute
   */
  public function testGenerateImageDerivativeFromScratch() {

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
    $this->getSession()->getPage()->findById("edit-action")->selectOption("Generate an image derivative");
    $this->getSession()->getPage()->pressButton(t('Create'));
    $this->assertSession()->statusCodeEquals(200);

    $this->getSession()->getPage()->fillField('edit-label', "Generate test derivative");
    $this->getSession()->getPage()->fillField('edit-id', "generate_test_derivative");
    $this->getSession()->getPage()->fillField('edit-queue', "generate-test-derivative");
    $this->getSession()->getPage()->fillField("edit-source-term", $this->preservationMasterTerm->label());
    $this->getSession()->getPage()->fillField("edit-derivative-term", $this->serviceFileTerm->label());
    $this->getSession()->getPage()->fillField('edit-mimetype', "image/jpeg");
    $this->getSession()->getPage()->fillField('edit-args', "-thumbnail 20x20");
    $this->getSession()->getPage()->fillField('edit-scheme', "public");
    $this->getSession()->getPage()->fillField('edit-path', "derp.jpeg");
    $this->getSession()->getPage()->pressButton(t('Save'));
    $this->assertSession()->statusCodeEquals(200);

    // Create a context and add the action as a derivative reaction.
    $this->createContext('Test', 'test');
    $this->addPresetReaction('test', 'derivative', "generate_test_derivative");
    $this->assertSession()->statusCodeEquals(200);

    // Create a new preservation master belonging to the node.
    $values = [
      'name[0][value]' => 'Test Media',
      'files[field_media_file_0]' => __DIR__ . '/../../fixtures/test_file.txt',
      'field_media_of[0][target_id]' => 'Test Node',
      'field_tags[0][target_id]' => 'Preservation Master',
    ];
    $this->drupalPostForm('media/add/' . $this->testMediaType->id(), $values, t('Save'));

    // Check the message gets published and is of the right shape.
    $this->checkMessage();
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
        $this->assertTrue(
          strpos($content['source_uri'], "test_file.txt") !== FALSE,
          "Expected source uri should contain the file."
        );
        $this->assertTrue(
          strpos($content['destination_uri'], "node/1/media/image/3") !== FALSE,
          "Expected destination uri should reference both node and term"
        );
        $this->assertTrue(
          strpos($content['file_upload_uri'], "public://derp.jpeg") !== FALSE,
          "Expected file upload uri should contain the scheme and path of the derivative"
        );

        $this->assertTrue($content['mimetype'] == 'image/jpeg', "Expected mimetype 'image/jpeg', received {$content['mimetype']}");

        $this->assertTrue($content['args'] == '-thumbnail 20x20', "Expected bundle '-thumbnail 20x20', received {$content['args']}");

      }
      $stomp->unsubscribe();
    }
    catch (StompException $e) {
      $this->assertTrue(FALSE, "There was an error connecting to the stomp broker");
    }
  }

}
