<?php

/*
 * This file is part of the FOSCommentBundle package.
 *
 * (c) FriendsOfSymfony <http://friendsofsymfony.github.com/>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace FOS\CommentBundle\Controller;

use FOS\CommentBundle\FormFactory\CommentableThreadFormFactoryInterface;
use FOS\CommentBundle\FormFactory\CommentFormFactoryInterface;
use FOS\CommentBundle\FormFactory\DeleteCommentFormFactoryInterface;
use FOS\CommentBundle\FormFactory\ThreadFormFactoryInterface;
use FOS\CommentBundle\FormFactory\VoteFormFactoryInterface;
use FOS\CommentBundle\Model\CommentInterface;
use FOS\CommentBundle\Model\CommentManagerInterface;
use FOS\CommentBundle\Model\ThreadInterface;
use FOS\CommentBundle\Model\ThreadManagerInterface;
use FOS\CommentBundle\Model\VotableCommentInterface;
use FOS\CommentBundle\Model\VoteManagerInterface;
use FOS\RestBundle\Controller\Annotations as Rest;
use FOS\RestBundle\View\View;
use FOS\RestBundle\View\ViewHandlerInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Restful controller for the Threads.
 * @Rest\Route("/threads")
 *
 * @author Alexander <iam.asm89@gmail.com>
 */
class ThreadController extends AbstractController
{
    const VIEW_FLAT = 'flat';

    const VIEW_TREE = 'tree';

    private ThreadManagerInterface $threadManager;

    private ViewHandlerInterface $viewHandler;

    private ThreadFormFactoryInterface $threadFormFactory;

    private CommentableThreadFormFactoryInterface $commentableThreadFormFactory;

    private CommentFormFactoryInterface $commentFormFactory;

    private DeleteCommentFormFactoryInterface $deleteCommentFormFactory;

    private CommentManagerInterface $commentManager;

    private ValidatorInterface $validator;

    private VoteManagerInterface $voteManager;

    private VoteFormFactoryInterface $voteFormFactory;

    public function __construct(
        ThreadManagerInterface $threadManager,
        ViewHandlerInterface $viewHandler,
        ThreadFormFactoryInterface $threadFormFactory,
        CommentableThreadFormFactoryInterface $commentableThreadFormFactory,
        CommentFormFactoryInterface $commentFormFactory,
        DeleteCommentFormFactoryInterface $deleteCommentFormFactory,
        CommentManagerInterface $commentManager,
        ValidatorInterface $validator,
        VoteManagerInterface $voteManager,
        VoteFormFactoryInterface $voteFormFactory
    ) {
        $this->threadManager                = $threadManager;
        $this->viewHandler                  = $viewHandler;
        $this->threadFormFactory            = $threadFormFactory;
        $this->commentableThreadFormFactory = $commentableThreadFormFactory;
        $this->commentFormFactory           = $commentFormFactory;
        $this->deleteCommentFormFactory     = $deleteCommentFormFactory;
        $this->commentManager               = $commentManager;
        $this->validator                    = $validator;
        $this->voteManager                  = $voteManager;
        $this->voteFormFactory              = $voteFormFactory;
    }

    /**
     * Presents the form to use to create a new Thread.
     * @Rest\Route("/new", name="new_threads", methods={"GET"})
     *
     * @return Response
     */
    public function newThreadsAction(): Response
    {
        $form = $this->threadFormFactory->createForm();

        return $this->renderForm('@FOSComment/Thread/new.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    /**
     * Gets the thread for a given id.
     * @Rest\Route("/{id}", name="get_thread", methods={"GET"})
     *
     * @param string $id
     *
     * @return Response
     */
    public function getThreadAction(string $id): Response
    {
        $manager = $this->threadManager;
        $thread  = $manager->findThreadById($id);

        if (null === $thread) {
            throw new NotFoundHttpException(sprintf("Thread with id '%s' could not be found.", $id));
        }

        $view = View::create()->setData([
            'thread' => $thread,
        ]);

        return $this->viewHandler->handle($view);
    }

    /**
     * Gets the threads for the specified ids.
     * @Rest\Route("", name="get_threads", methods={"GET"})
     *
     * @param Request $request
     *
     * @return Response
     */
    public function getThreadsActions(Request $request): Response
    {
        $ids = $request->query->get('ids');

        if (null === $ids) {
            throw new NotFoundHttpException('Cannot query threads without id\'s.');
        }

        $threads = $this->threadManager->findThreadsBy([
            'id' => $ids,
        ]);

        $view = View::create()->setData([
            'threads' => $threads,
        ]);

        return $this->viewHandler->handle($view);
    }

    /**
     * Creates a new Thread from the submitted data.
     * @Rest\Route("", name="post_threads", methods={"POST"})
     *
     * @param Request $request The current request
     *
     * @return Response
     */
    public function postThreadsAction(Request $request): Response
    {
        $thread = $this->threadManager->createThread();
        $form   = $this->threadFormFactory->createForm();
        $form->setData($thread);
        $form->handleRequest($request);

        if ($form->isValid()) {
            if (null !== $this->threadManager->findThreadById($thread->getId())) {
                $this->onCreateThreadErrorDuplicate($form);
            }

            $this->threadManager->saveThread($thread);

            return $this->onCreateThreadSuccess($form);
        }

        return $this->onCreateThreadError($form);
    }

    /**
     * Get the edit form the open/close a thread.
     * @Rest\Route("/{id}/commentable/edit", name="edit_thread_commentable", methods={"GET"})
     *
     * @param Request $request Current request
     * @param int     $id      Thread id
     *
     * @return Response
     */
    public function editThreadCommentableAction(Request $request, int $id): Response
    {
        $thread = $this->threadManager->findThreadById($id);

        if (null === $thread) {
            throw new NotFoundHttpException(sprintf("Thread with id '%s' could not be found.", $id));
        }

        $thread->setCommentable($request->query->get('value', 1));

        $form = $this->commentableThreadFormFactory->createForm();
        $form->setData($thread);

        return $this->render('@FOSComment/Thread/commentable.html.twig', [
            'form'          => $form->createView(),
            'id'            => $id,
            'isCommentable' => $thread->isCommentable(),
        ]);
    }

    /**
     * Edits the thread.
     * @Rest\Route("/{id}/commentable", name="patch_thread_commentable", methods={"PATCH"})
     *
     * @param Request $request Currently request
     * @param int     $id      Thread id
     *
     * @return Response
     */
    public function patchThreadCommentableAction(Request $request, int $id): Response
    {
        $thread = $this->threadManager->findThreadById($id);

        if (null === $thread) {
            throw new NotFoundHttpException(sprintf("Thread with id '%s' could not be found.", $id));
        }

        $form = $this->commentableThreadFormFactory->createForm();
        $form->setData($thread);
        $form->handleRequest($request);

        if ($form->isValid()) {
            $this->threadManager->saveThread($thread);

            return $this->onOpenThreadSuccess($form);
        }

        return $this->onOpenThreadError($form);
    }

    /**
     * Presents the form to use to create a new Comment for a Thread.
     * @Rest\Route("/{id}/comments/new", name="new_thread_comments", methods={"GET"})
     *
     * @param Request $request
     * @param string  $id
     *
     * @return Response
     */
    public function newThreadCommentsAction(Request $request, string $id): Response
    {
        $thread = $this->threadManager->findThreadById($id);
        if (!$thread) {
            throw new NotFoundHttpException(sprintf('Thread with identifier of "%s" does not exist', $id));
        }

        $comment = $this->commentManager->createComment($thread);

        $parent = $this->getValidCommentParent($thread, $request->query->get('parentId'));

        $form = $this->commentFormFactory->createForm();
        $form->setData($comment);

        return $this->render('@FOSComment/Thread/comment_new.html.twig', [
            'form'   => $form->createView(),
            'first'  => 0 === $thread->getNumComments(),
            'thread' => $thread,
            'parent' => $parent,
            'id'     => $id,
        ]);
    }

    /**
     * Get a comment of a thread.
     * @Rest\Route("/{id}/comments/{commentId}", name="get_thread_comment", methods={"GET"})
     *
     * @param string $id        ID of the thread
     * @param mixed  $commentId ID of the comment
     *
     * @return Response
     */
    public function getThreadCommentAction(string $id, mixed $commentId): Response
    {
        $thread  = $this->threadManager->findThreadById($id);
        $comment = $this->commentManager->findCommentById($commentId);
        $parent  = null;

        if (null === $thread || null === $comment || $comment->getThread() !== $thread) {
            throw new NotFoundHttpException(
                sprintf("No comment with id '%s' found for thread with id '%s'", $commentId, $id)
            );
        }

        $ancestors = $comment->getAncestors();
        if (count($ancestors) > 0) {
            $parent = $this->getValidCommentParent($thread, $ancestors[count($ancestors) - 1]);
        }

        return $this->render('@FOSComment/Thread/comment.html.twig', [
            'comment' => $comment,
            'thread'  => $thread,
            'parent'  => $parent,
            'depth'   => $comment->getDepth(),
        ]);
    }

    /**
     * Get the delete form for a comment.
     * @Rest\Route("/{id}/comments/{commentId}/remove", name="remove_thread_comment", methods={"GET"})
     *
     * @param Request $request   Current request
     * @param string  $id        ID of the thread
     * @param mixed   $commentId ID of the comment
     *
     * @return Response
     */
    public function removeThreadCommentAction(Request $request, string $id, mixed $commentId): Response
    {
        $thread  = $this->threadManager->findThreadById($id);
        $comment = $this->commentManager->findCommentById($commentId);

        if (null === $thread || null === $comment || $comment->getThread() !== $thread) {
            throw new NotFoundHttpException(
                sprintf("No comment with id '%s' found for thread with id '%s'", $commentId, $id)
            );
        }

        $form = $this->deleteCommentFormFactory->createForm();
        $comment->setState($request->query->get('value', $comment::STATE_DELETED));

        $form->setData($comment);

        return $this->render('@FOSComment/Thread/comment_remove.html.twig', [
            'form'      => $form->createView(),
            'id'        => $id,
            'commentId' => $commentId,
        ]);
    }

    /**
     * Edits the comment state.
     * @Rest\Route("/{id}/comments/{commentId}/state", name="patch_thread_comment_state", methods={"PATCH"})
     *
     * @param Request $request   Current request
     * @param string  $id        Thread id
     * @param mixed   $commentId ID of the comment
     *
     * @return Response
     */
    public function patchThreadCommentStateAction(Request $request, string $id, mixed $commentId): Response
    {
        $thread  = $this->threadManager->findThreadById($id);
        $comment = $this->commentManager->findCommentById($commentId);

        if (null === $thread || null === $comment || $comment->getThread() !== $thread) {
            throw new NotFoundHttpException(sprintf("No comment with id '%s' found for thread with id '%s'", $commentId,
                $id));
        }

        $form = $this->deleteCommentFormFactory->createForm();
        $form->setData($comment);
        $form->handleRequest($request);

        if ($form->isValid()) {
            if (false !== $this->commentManager->saveComment($comment)) {
                return $this->onRemoveThreadCommentSuccess($form, $id);
            }
        }

        return $this->onRemoveThreadCommentError($form, $id);
    }

    /**
     * Presents the form to use to edit a Comment for a Thread.
     * @Rest\Route("/{id}/comments/{commentId}/edit", name="edit_thread_comment", methods={"GET"})
     *
     * @param string $id        ID of the thread
     * @param mixed  $commentId ID of the comment
     *
     * @return Response
     */
    public function editThreadCommentAction(string $id, mixed $commentId): Response
    {
        $form = $this->getThreadCommentsForm($id, $commentId);

        return $this->render('@FOSComment/Thread/comment_edit.html.twig', [
            'form'    => $form->createView(),
            'comment' => $form->getData(),
        ]);
    }

    /**
     * Edits a given comment.
     * @Rest\Route("/{id}/comments/{commentId}", name="put_thread_comments", methods={"PUT"})
     *
     * @param Request $request   Current request
     * @param string  $id        ID of the thread
     * @param mixed   $commentId ID of the comment
     *
     * @return Response
     */
    public function putThreadCommentsAction(Request $request, string $id, mixed $commentId): Response
    {
        $form = $this->getThreadCommentsForm($id, $commentId);
        $form->handleRequest($request);

        if ($form->isValid()) {
            if (false !== $this->commentManager->saveComment($form->getData())) {
                return $this->onEditCommentSuccess($form, $id);
            }
        }

        return $this->onEditCommentError($form);
    }

    /**
     * Get the comments of a thread. Creates a new thread if none exists.
     * @Rest\Route("/{id}/comments", name="get_thread_comments", methods={"GET"})
     *
     * @param Request $request Current request
     * @param string  $id      ID of the thread
     *
     * @return Response
     *
     * @todo Add support page/pagesize/sorting/tree-depth parameters
     */
    public function getThreadCommentsAction(Request $request, string $id): Response
    {
        $displayDepth = $request->query->get('displayDepth');
        $sorter       = $request->query->get('sorter');
        $thread       = $this->threadManager->findThreadById($id);

        // We're now sure it is no duplicate id, so create the thread
        if (null === $thread) {
            $permalink = $request->query->get('permalink');

            $thread = $this->threadManager->createThread();
            $thread->setId($id);
            $thread->setPermalink($permalink);

            // Validate the entity
            $errors = $this->validator->validate($thread, null, ['NewThread']);
            if (count($errors) > 0) {
                return $this->render('@FOSComment/Thread/errors.html.twig', [
                    'errors' => $errors,
                ])->setStatusCode(Response::HTTP_BAD_REQUEST);
            }

            // Decode the permalink for cleaner storage (it is encoded on the client side)
            $thread->setPermalink(urldecode($permalink));

            // Add the thread
            $this->threadManager->saveThread($thread);
        }

        $viewMode = $request->query->get('view', 'tree');
        switch ($viewMode) {
            case self::VIEW_FLAT:
                $comments = $this->commentManager->findCommentsByThread($thread, $displayDepth, $sorter);

                // We need nodes for the api to return a consistent response, not an array of comments
                $comments = array_map(function ($comment) {
                    return ['comment' => $comment, 'children' => []];
                }, $comments);
                break;
            case self::VIEW_TREE:
            default:
                $comments = $this->commentManager->findCommentTreeByThread($thread, $sorter, $displayDepth);
                break;
        }

        $data = [
            'comments'     => $comments,
            'displayDepth' => $displayDepth,
            'sorter'       => 'date',
            'thread'       => $thread,
            'view'         => $viewMode,
        ];

        if ('rss' === $request->getRequestFormat()) {
            return $this->render('@FOSComment/Thread/thread_xml_feed.rss.twig', $data);
        } else {
            return $this->render('@FOSComment/Thread/comments.html.twig', $data);
        }
    }

    /**
     * Creates a new Comment for the Thread from the submitted data.
     * @Rest\Route("/{id}/comments", name="post_thread_comments", methods={"POST"})
     *
     * @param Request $request The current request
     * @param string  $id      The ID of the thread
     *
     * @return Response
     *
     * @todo Add support for comment parent (in form?)
     */
    public function postThreadCommentsAction(Request $request, string $id): Response
    {
        $thread = $this->threadManager->findThreadById($id);
        if (!$thread) {
            throw new NotFoundHttpException(sprintf('Thread with identifier of "%s" does not exist', $id));
        }

        if (!$thread->isCommentable()) {
            throw new AccessDeniedHttpException(sprintf('Thread "%s" is not commentable', $id));
        }

        $parent  = $this->getValidCommentParent($thread, $request->query->get('parentId'));
        $comment = $this->commentManager->createComment($thread, $parent);

        $form = $this->commentFormFactory->createForm(null, ['method' => 'POST']);
        $form->setData($comment);
        $form->handleRequest($request);

        if ($form->isValid()) {
            if (false !== $this->commentManager->saveComment($comment)) {
                return $this->onCreateCommentSuccess($form, $id);
            }
        }

        return $this->onCreateCommentError($form, $id, $parent);
    }

    /**
     * Get the votes of a comment.
     * @Rest\Route("/{id}/comments/{commentId}/votes", name="get_thread_comment_votes", methods={"GET"})
     *
     * @param string $id        ID of the thread
     * @param mixed  $commentId ID of the comment
     *
     * @return Response
     */
    public function getThreadCommentVotesAction(string $id, mixed $commentId): Response
    {
        $thread  = $this->threadManager->findThreadById($id);
        $comment = $this->commentManager->findCommentById($commentId);

        if (null === $thread || null === $comment || $comment->getThread() !== $thread) {
            throw new NotFoundHttpException(
                sprintf("No comment with id '%s' found for thread with id '%s'", $commentId, $id)
            );
        }

        return $this->render('@FOSComment/Thread/comment_votes.html.twig', [
            'commentScore' => ($comment instanceof VotableCommentInterface) ? $comment->getScore() : 0,
        ]);
    }

    /**
     * Presents the form to use to create a new Vote for a Comment.
     * @Rest\Route("/{id}/comments/{commentId}/votes/new", name="new_thread_comment_votes", methods={"GET"})
     *
     * @param Request $request   Current request
     * @param string  $id        ID of the thread
     * @param mixed   $commentId ID of the comment
     *
     * @return Response
     */
    public function newThreadCommentVotesAction(Request $request, string $id, mixed $commentId): Response
    {
        $thread  = $this->threadManager->findThreadById($id);
        $comment = $this->commentManager->findCommentById($commentId);

        if (null === $thread || null === $comment || $comment->getThread() !== $thread || !$comment instanceof VotableCommentInterface) {
            throw new NotFoundHttpException(
                sprintf("No comment with id '%s' found for thread with id '%s'", $commentId, $id)
            );
        }

        $vote = $this->voteManager->createVote($comment);
        $vote->setValue($request->query->get('value', 1));

        $form = $this->voteFormFactory->createForm();
        $form->setData($vote);

        return $this->render('@FOSComment/Thread/vote_new.html.twig', [
            'id'        => $id,
            'commentId' => $commentId,
            'form'      => $form->createView(),
        ]);
    }

    /**
     * Creates a new Vote for the Comment from the submitted data.
     * @Rest\Route("/{id}/comments/{commentId}/votes", name="post_thread_comment_votes", methods={"POST"})
     *
     * @param Request $request   Current request
     * @param string  $id        ID of the thread
     * @param mixed   $commentId ID of the comment
     *
     * @return Response
     */
    public function postThreadCommentVotesAction(Request $request, string $id, mixed $commentId): Response
    {
        $thread  = $this->threadManager->findThreadById($id);
        $comment = $this->commentManager->findCommentById($commentId);

        if (null === $thread || null === $comment || $comment->getThread() !== $thread || !$comment instanceof VotableCommentInterface) {
            throw new NotFoundHttpException(
                sprintf("No comment with id '%s' found for thread with id '%s'", $commentId, $id)
            );
        }

        $voteManager = $this->voteManager;
        $vote        = $voteManager->createVote($comment);

        $form = $this->voteFormFactory->createForm();
        $form->setData($vote);
        $form->handleRequest($request);

        if ($form->isValid()) {
            $voteManager->saveVote($vote);

            return $this->onCreateVoteSuccess($id, $commentId);
        }

        return $this->onCreateVoteError($form, $id, $commentId);
    }

    /**
     * Forwards the action to the comment view on a successful form submission.
     *
     * @param FormInterface $form Form with the error
     * @param string        $id   ID of the thread
     *
     * @return Response
     */
    protected function onCreateCommentSuccess(FormInterface $form, string $id): Response
    {
        return $this->redirectToRoute('fos_comment_get_thread_comment', [
            'id'        => $id,
            'commentId' => $form->getData()->getId(),
        ]);
    }

    /**
     * Returns an HTTP_BAD_REQUEST response when the form submission fails.
     *
     * @param FormInterface         $form   Form with the error
     * @param string                $id     ID of the thread
     * @param CommentInterface|null $parent Optional comment parent
     *
     * @return Response
     */
    protected function onCreateCommentError(FormInterface $form, string $id, ?CommentInterface $parent = null): Response
    {
        return $this->render('@FOSComment/Thread/comment_new.html.twig', [
            'form'   => $form->createView(),
            'id'     => $id,
            'parent' => $parent,
        ])->setStatusCode(Response::HTTP_BAD_REQUEST);
    }

    /**
     * Forwards the action to the thread view on a successful form submission.
     *
     * @param FormInterface $form
     *
     * @return Response
     */
    protected function onCreateThreadSuccess(FormInterface $form): Response
    {
        return $this->redirectToRoute('fos_comment_get_thread', [
            'id' => $form->getData()->getId(),
        ]);
    }

    /**
     * Returns an HTTP_BAD_REQUEST response when the form submission fails.
     *
     * @param FormInterface $form
     *
     * @return Response
     */
    protected function onCreateThreadError(FormInterface $form): Response
    {
        return $this->render('@FOSComment/Thread/new.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    /**
     * Returns an HTTP_BAD_REQUEST response when the Thread creation fails due to a duplicate id.
     *
     * @param FormInterface $form
     *
     * @return Response
     */
    protected function onCreateThreadErrorDuplicate(FormInterface $form): Response
    {
        return new Response(sprintf("Duplicate thread id '%s'.", $form->getData()->getId()),
            Response::HTTP_BAD_REQUEST);
    }

    /**
     * Action executed when a vote was successfully created.
     *
     * @param string $id        ID of the thread
     * @param mixed  $commentId ID of the comment
     *
     * @return Response
     *
     * @todo Think about what to show. For now the new score of the comment
     */
    protected function onCreateVoteSuccess(string $id, mixed $commentId): Response
    {
        return $this->redirectToRoute('fos_comment_get_thread_comment_votes', [
            'id'        => $id,
            'commentId' => $commentId,
        ]);
    }

    /**
     * Returns an HTTP_BAD_REQUEST response when the form submission fails.
     *
     * @param FormInterface $form      Form with the error
     * @param string        $id        ID of the thread
     * @param mixed         $commentId ID of the comment
     *
     * @return Response
     */
    protected function onCreateVoteError(FormInterface $form, string $id, mixed $commentId): Response
    {
        return $this->render('@FOSComment/Thread/vote_new.html.twig', [
            'id'        => $id,
            'commentId' => $commentId,
            'form'      => $form->createView(),
        ])->setStatusCode(Response::HTTP_BAD_REQUEST);
    }

    /**
     * Forwards the action to the comment view on a successful form submission.
     *
     * @param FormInterface $form Form with the error
     * @param string        $id   ID of the thread
     *
     * @return Response
     */
    protected function onEditCommentSuccess(FormInterface $form, string $id): Response
    {
        return $this->redirectToRoute('fos_comment_get_thread_comment', [
            'id'        => $id,
            'commentId' => $form->getData()->getId(),
        ]);
    }

    /**
     * Returns an HTTP_BAD_REQUEST response when the form submission fails.
     *
     * @param FormInterface $form Form with the error
     *
     * @return Response
     */
    protected function onEditCommentError(FormInterface $form): Response
    {
        return $this->render('@FOSComment/Thread/comment_edit.html.twig', [
            'form'    => $form->createView(),
            'comment' => $form->getData(),
        ])->setStatusCode(Response::HTTP_BAD_REQUEST);
    }

    /**
     * Forwards the action to the open thread edit view on a successful form submission.
     *
     * @param FormInterface $form
     *
     * @return Response
     */
    protected function onOpenThreadSuccess(FormInterface $form): Response
    {
        return $this->redirectToRoute('fos_comment_edit_thread_commentable', [
            'id'    => $form->getData()->getId(),
            'value' => !$form->getData()->isCommentable(),
        ]);
    }

    /**
     * Returns an HTTP_BAD_REQUEST response when the form submission fails.
     *
     * @param FormInterface $form
     *
     * @return Response
     */
    protected function onOpenThreadError(FormInterface $form): Response
    {
        return $this->render('@FOSComment/Thread/commentable.html.twig', [
            'form'          => $form->createView(),
            'id'            => $form->getData()->getId(),
            'isCommentable' => $form->getData()->isCommentable(),
        ])->setStatusCode(Response::HTTP_BAD_REQUEST);
    }

    /**
     * Forwards the action to the comment view on a successful form submission.
     *
     * @param FormInterface $form Comment delete form
     * @param int           $id   Thread id
     *
     * @return Response
     */
    protected function onRemoveThreadCommentSuccess(FormInterface $form, int $id): Response
    {
        return $this->redirectToRoute('fos_comment_get_thread_comment', [
            'id'        => $id,
            'commentId' => $form->getData()->getId(),
        ]);
    }

    /**
     * Returns an HTTP_BAD_REQUEST response when the form submission fails.
     *
     * @param FormInterface $form Comment delete form
     * @param int           $id   Thread id
     *
     * @return Response
     */
    protected function onRemoveThreadCommentError(FormInterface $form, int $id): Response
    {
        return $this->render('@FOSComment/Thread/comment_remove.html.twig', [
            'form'      => $form->createView(),
            'id'        => $id,
            'commentId' => $form->getData()->getId(),
            'value'     => $form->getData()->getState(),
        ])->setStatusCode(Response::HTTP_BAD_REQUEST);
    }

    /**
     * Checks if a comment belongs to a thread. Returns the comment if it does.
     *
     * @param ThreadInterface $thread    Thread object
     * @param mixed           $commentId ID of the comment
     *
     * @return CommentInterface|null The comment
     */
    private function getValidCommentParent(ThreadInterface $thread, mixed $commentId): ?CommentInterface
    {
        if (null === $commentId) {
            return null;
        }

        $comment = $this->commentManager->findCommentById($commentId);
        if (!$comment) {
            throw new NotFoundHttpException(sprintf('Parent comment with identifier "%s" does not exist',
                $commentId));
        }

        if ($comment->getThread() !== $thread) {
            throw new NotFoundHttpException('Parent comment is not a comment of the given thread.');
        }

        return $comment;
    }

    private function getThreadCommentsForm(int $id, mixed $commentId): FormInterface
    {
        $thread  = $this->threadManager->findThreadById($id);
        $comment = $this->commentManager->findCommentById($commentId);

        if (null === $thread || null === $comment || $comment->getThread() !== $thread) {
            throw new NotFoundHttpException(
                sprintf("No comment with id '%s' found for thread with id '%s'", $commentId, $id)
            );
        }

        $form = $this->commentFormFactory->createForm(null, ['method' => 'PUT']);
        $form->setData($comment);

        return $form;
    }
}
