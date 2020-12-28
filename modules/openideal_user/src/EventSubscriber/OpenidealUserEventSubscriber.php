<?php

namespace Drupal\openideal_user\EventSubscriber;

use Drupal\Component\Datetime\Time;
use Drupal\Core\Session\AccountProxy;
use Drupal\ckeditor_mentions\CKEditorMentionEvent;
use Drupal\comment\Entity\Comment;
use Drupal\content_moderation\Event\ContentModerationEvents;
use Drupal\content_moderation\Event\ContentModerationStateChangedEvent;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\openideal_user\Event\OpenidealContentModerationEvent;
use Drupal\openideal_user\Event\OpenidealUserEvents;
use Drupal\openideal_user\Event\OpenidealUserMentionEvent;
use Drupal\user\UserDataInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\FilterResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Openideal user general event subscriber.
 */
class OpenidealUserEventSubscriber implements EventSubscriberInterface {

  /**
   * Tour related information.
   */
  const TOUR_SHOWED = 'tour_displayed';

  /**
   * Event dispatcher.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected $eventDispatcher;

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Current user.
   *
   * @var \Drupal\Core\Session\AccountProxy
   */
  protected $currentUser;

  /**
   * User data.
   *
   * @var \Drupal\user\UserDataInterface
   */
  protected $userData;

  /**
   * Time.
   *
   * @var \Drupal\Component\Datetime\Time
   */
  protected $time;

  /**
   * OpenidealSocialAuthSubscriber constructor.
   *
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $event_dispatcher
   *   Event dispatcher.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   Entity type manager.
   * @param \Drupal\Component\Datetime\Time $time
   *   Time.
   * @param \Drupal\Core\Session\AccountProxy $currentUser
   *   Current user.
   * @param \Drupal\user\UserDataInterface $userData
   *   User data.
   */
  public function __construct(
    EventDispatcherInterface $event_dispatcher,
    EntityTypeManagerInterface $entity_type_manager,
    Time $time,
    AccountProxy $currentUser,
    UserDataInterface $userData
  ) {
    $this->eventDispatcher = $event_dispatcher;
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $currentUser;
    $this->userData = $userData;
    $this->time = $time;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      CKEditorMentionEvent::MENTION_FIRST => 'usersAreMentioned',
      ContentModerationEvents::STATE_CHANGED => 'stateChanged',
      KernelEvents::RESPONSE => 'response',
    ];
  }

  /**
   * This method is called when the RESPONSE event is dispatched.
   *
   * @param \Symfony\Component\HttpKernel\Event\FilterResponseEvent $event
   *   Dispatched event.
   */
  public function response(FilterResponseEvent $event) {
    $request = $event->getRequest();
    $route_name = $request->get('_route');
    if ($route_name == 'view.frontpage.front_page' && !$request->query->has('tour')) {
      // Set a cookie for anonymous user when
      // visits front page for the first time.
      if ($this->currentUser->isAnonymous() && !$event->getRequest()->cookies->has(self::TOUR_SHOWED) && $this->isTourAttached($route_name, 'openideal-welcome')) {
        $this->generateRedirectResponse($event);
      }
      // Same with authenticated user, set indicator into user.data
      // service that user has already visited frontpage.
      elseif (!$this->currentUser->isAnonymous() && !$this->userData->get('openideal_user', $this->currentUser->id(), self::TOUR_SHOWED) && $this->isTourAttached($route_name, 'openideal-welcome')) {
        $this->userData->set('openideal_user', $this->currentUser->id(), self::TOUR_SHOWED, '1');
        $this->generateRedirectResponse($event);
      }
    }
  }

  /**
   * This method is called when the MENTION_FIRST event is dispatched.
   *
   * @param \Drupal\ckeditor_mentions\CKEditorMentionEvent $event
   *   The dispatched event.
   */
  public function usersAreMentioned(CKEditorMentionEvent $event) {
    if ((($comment = $event->getEntity()) instanceof Comment) && !empty($event->getMentionedUsers())) {
      // If user was mentioned twice in comment remove it.
      $user_ids = array_unique(array_keys($event->getMentionedUsers()));
      foreach ($user_ids as $id) {
        $storage = $this->entityTypeManager->getStorage('user');
        $user = $storage->load($id);
        $event = new OpenidealUserMentionEvent($comment, $user);
        $this->eventDispatcher->dispatch(OpenidealUserEvents::OPENIDEAL_USER_MENTION, $event);
      }
    }
  }

  /**
   * This method is called when the MENTION_FIRST event is dispatched.
   *
   * @param \Drupal\content_moderation\Event\ContentModerationStateChangedEvent $event
   *   The dispatched event.
   */
  public function stateChanged(ContentModerationStateChangedEvent $event) {
    $event = new OpenidealContentModerationEvent($event);
    $this->eventDispatcher->dispatch(OpenidealUserEvents::WORKFLOW_STATE_CHANGED, $event);
  }

  /**
   * Generate redirect response.
   *
   * @param \Symfony\Component\HttpKernel\Event\FilterResponseEvent $event
   *   Event.
   */
  protected function generateRedirectResponse(FilterResponseEvent $event) {
    $request = $event->getRequest();
    $request->query->set('tour', 'start');
    $url = Url::fromRoute($request->get('_route'))->mergeOptions(['query' => $request->query->all()])->toString();
    $response = new RedirectResponse($url);
    $event->setResponse($response);
  }

  /**
   * Checks if tour attached to current page.
   *
   * @param string $route_name
   *   Page to check.
   * @param string $tour_name
   *   Tour name.
   *
   * @return bool
   *   TRUE if attached, false otherwise.
   */
  protected function isTourAttached(string $route_name, $tour_name = NULL) {
    $results = $this->entityTypeManager->getStorage('tour')->getQuery()
      ->condition('routes.*.route_name', $route_name)
      ->execute();
    return !empty($results) ? ((isset($tour_name) ? in_array($tour_name, $results) : TRUE)) : FALSE;
  }

}
