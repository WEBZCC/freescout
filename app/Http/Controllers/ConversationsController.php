<?php

namespace App\Http\Controllers;

use App\Attachment;
use App\Conversation;
use App\Customer;
use App\Events\ConversationStatusChanged;
use App\Events\ConversationUserChanged;
use App\Events\UserCreatedConversation;
use App\Events\UserReplied;
use App\Folder;
use App\Mailbox;
use App\MailboxUser;
use App\Thread;
use Illuminate\Http\Request;
use Validator;

class ConversationsController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * View conversation.
     */
    public function view(Request $request, $id)
    {
        $conversation = Conversation::findOrFail($id);
        $this->authorize('view', $conversation);

        $user = auth()->user();

        // Detect folder
        $folder = null;
        if (Conversation::getFolderParam()) {
            $folder = $conversation->mailbox->folders()->where('folders.id', Conversation::getFolderParam())->first();

            // Check if conversation can be located in the passed folder_id
            if (!$conversation->isInFolderAllowed($folder)) {
                $request->session()->reflash();

                return redirect()->away($conversation->url($conversation->folder_id));
            }
        }

        if (!$folder) {
            if ($conversation->user_id == $user->id) {
                $folder = $conversation->mailbox->folders()->where('type', Folder::TYPE_MINE)->first();
            } else {
                $folder = $conversation->folder;
            }

            return redirect()->away($conversation->url($folder->id));
        }

        $after_send = $conversation->mailbox->getUserSettings($user->id)->after_send;

        return view('conversations/view', [
            'conversation' => $conversation,
            'mailbox'      => $conversation->mailbox,
            'customer'     => $conversation->customer,
            'threads'      => $conversation->threads()->orderBy('created_at', 'desc')->get(),
            'folder'       => $folder,
            'folders'      => $conversation->mailbox->getAssesibleFolders(),
            'after_send'   => $after_send,
        ]);
    }

    /**
     * New conversation.
     */
    public function create($mailbox_id)
    {
        $mailbox = Mailbox::findOrFail($mailbox_id);
        $this->authorize('view', $mailbox);

        $conversation = new Conversation();
        $conversation->body = '';

        $folder = $mailbox->folders()->where('type', Folder::TYPE_DRAFTS)->first();

        $after_send = $mailbox->getUserSettings(auth()->user()->id)->after_send;

        return view('conversations/create', [
            'conversation' => $conversation,
            'mailbox'      => $mailbox,
            'folder'       => $folder,
            'folders'      => $mailbox->getAssesibleFolders(),
            'after_send'   => $after_send,
        ]);
    }

    /**
     * Conversation draft.
     */
    public function draft($id)
    {
        $conversation = Conversation::findOrFail($id);

        $this->authorize('view', $conversation);

        return view('conversations/create', [
            'conversation' => $conversation,
            'mailbox'      => $conversation->mailbox,
            'folder'       => $conversation->folder,
            'folders'      => $conversation->mailbox->getAssesibleFolders(),
        ]);
    }

    /**
     * Conversations ajax controller.
     */
    public function ajax(Request $request)
    {
        $response = [
            'status' => 'error',
            'msg'    => '', // this is error message
        ];

        $user = auth()->user();

        switch ($request->action) {

            // Change conversation user
            case 'conversation_change_user':
                $conversation = Conversation::find($request->conversation_id);

                $new_user_id = (int) $request->user_id;

                if (!$conversation) {
                    $response['msg'] = __('Conversation not found');
                }
                if (!$response['msg'] && $conversation->user_id == $new_user_id) {
                    $response['msg'] = __('Assignee already set');
                }
                if (!$response['msg'] && !$user->can('update', $conversation)) {
                    $response['msg'] = __('Not enough permissions');
                }
                if (!$response['msg'] && (int) $new_user_id != -1 && !in_array($new_user_id, $conversation->mailbox->userIdsHavingAccess())) {
                    $response['msg'] = __('Incorrect user');
                }
                if (!$response['msg']) {
                    // Next conversation has to be determined before updating status for current one
                    $next_conversation = $conversation->getNearby();

                    $conversation->setUser($new_user_id);
                    $conversation->save();

                    // Create lineitem thread
                    $thread = new Thread();
                    $thread->conversation_id = $conversation->id;
                    $thread->user_id = $conversation->user_id;
                    $thread->type = Thread::TYPE_LINEITEM;
                    $thread->state = Thread::STATE_PUBLISHED;
                    $thread->status = Thread::STATUS_NOCHANGE;
                    $thread->action_type = Thread::ACTION_TYPE_USER_CHANGED;
                    $thread->source_via = Thread::PERSON_USER;
                    // todo: this need to be changed for API
                    $thread->source_type = Thread::SOURCE_TYPE_WEB;
                    $thread->customer_id = $conversation->customer_id;
                    $thread->created_by_user_id = $user->id;
                    $thread->save();

                    event(new ConversationUserChanged($conversation, $user));

                    $response['status'] = 'success';

                    // Flash
                    $flash_message = __('Assignee updated');
                    if ($new_user_id != $user->id) {
                        $flash_message .= ' <a href="'.$conversation->url().'">'.__('View').'</a>';

                        if ($next_conversation) {
                            $response['redirect_url'] = $next_conversation->url();
                        } else {
                            // Show conversations list
                            $response['redirect_url'] = route('mailboxes.view.folder', ['id' => $conversation->mailbox_id, 'folder_id' => $conversation->folder_id]);
                        }
                    }
                    \Session::flash('flash_success_floating', $flash_message);

                    $response['msg'] = __('Assignee updated');
                }
                break;

            // Change conversation status
            case 'conversation_change_status':
                $conversation = Conversation::find($request->conversation_id);

                $new_status = (int) $request->status;

                if (!$conversation) {
                    $response['msg'] = __('Conversation not found');
                }
                if (!$response['msg'] && $conversation->status == $new_status) {
                    $response['msg'] = __('Status already set');
                }
                if (!$response['msg'] && !$user->can('update', $conversation)) {
                    $response['msg'] = __('Not enough permissions');
                }
                if (!$response['msg'] && !in_array((int) $request->status, array_keys(Conversation::$statuses))) {
                    $response['msg'] = __('Incorrect status');
                }
                if (!$response['msg']) {
                    // Next conversation has to be determined before updating status for current one
                    $next_conversation = $conversation->getNearby();

                    $conversation->setStatus($new_status, $user);
                    $conversation->save();

                    // Create lineitem thread
                    $thread = new Thread();
                    $thread->conversation_id = $conversation->id;
                    $thread->user_id = $conversation->user_id;
                    $thread->type = Thread::TYPE_LINEITEM;
                    $thread->state = Thread::STATE_PUBLISHED;
                    $thread->status = $conversation->status;
                    $thread->action_type = Thread::ACTION_TYPE_STATUS_CHANGED;
                    $thread->source_via = Thread::PERSON_USER;
                    // todo: this need to be changed for API
                    $thread->source_type = Thread::SOURCE_TYPE_WEB;
                    $thread->customer_id = $conversation->customer_id;
                    $thread->created_by_user_id = $user->id;
                    $thread->save();

                    event(new ConversationStatusChanged($conversation));

                    $response['status'] = 'success';

                    // Flash
                    $flash_message = __('Status updated');
                    if ($new_status != Conversation::STATUS_ACTIVE) {
                        $flash_message .= ' <a href="'.$conversation->url().'">'.__('View').'</a>';

                        if ($next_conversation) {
                            $response['redirect_url'] = $next_conversation->url();
                        } else {
                            // Show conversations list
                            $response['redirect_url'] = route('mailboxes.view.folder', ['id' => $conversation->mailbox_id, 'folder_id' => $conversation->folder_id]);
                        }
                    }
                    \Session::flash('flash_success_floating', $flash_message);

                    $response['msg'] = __('Status updated');
                }
                break;

             // Send reply or new conversation
            case 'send_reply':

                $mailbox = Mailbox::findOrFail($request->mailbox_id);

                if (!$response['msg'] && !$user->can('view', $mailbox)) {
                    $response['msg'] = __('Not enough permissions');
                }

                $conversation = null;
                if (!$response['msg'] && !empty($request->conversation_id)) {
                    $conversation = Conversation::find($request->conversation_id);
                    if ($conversation && !$user->can('view', $conversation)) {
                        $response['msg'] = __('Not enough permissions');
                    }
                }

                $new = false;
                if (empty($request->conversation_id)) {
                    $new = true;
                }

                if (!$response['msg']) {
                    $validator = Validator::make($request->all(), [
                        'to'       => 'required|string',
                        'subject'  => 'required|string|max:998',
                        'body'     => 'required|string',
                        'cc'       => 'nullable|string',
                        'bcc'      => 'nullable|string',
                    ]);

                    if ($validator->fails()) {
                        foreach ($validator->errors() as $errors) {
                            foreach ($errors as $field => $message) {
                                $response['msg'] .= $message.' ';
                            }
                        }
                        // return redirect()->route('conversations.create', ['mailbox_id' => $mailbox_id])
                        //             ->withErrors($validator)
                        //             ->withInput();
                    }
                }

                if (!$response['msg'] && $new) {
                    $to_array = Conversation::sanitizeEmails($request->to);

                    // Check if there are any emails
                    if (!$to_array) {
                        $response['msg'] .= __('Incorrect recipients');
                        // return redirect()->route('conversations.create', ['mailbox_id' => $mailbox_id])
                        //             ->withErrors(['to' => __('Incorrect recipients')])
                        //             ->withInput();
                    }
                }

                if (!$response['msg']) {
                    // Check attachments
                    $has_attachments = false;
                    $attachments = [];
                    if (!empty($request->attachments_all)) {
                        $embeds = [];
                        if (!empty($request->attachments)) {
                            $attachments = $request->attachments;
                        }
                        if (!empty($request->embeds)) {
                            $embeds = $request->embeds;
                        }
                        if (count($attachments) != count($embeds)) {
                            $has_attachments = true;
                        }
                        $attachments_to_remove = array_diff($request->attachments_all, $attachments);
                        $attachments_to_remove = array_diff($attachments_to_remove, $embeds);
                        Attachment::deleteByIds($attachments_to_remove);
                    }

                    $now = date('Y-m-d H:i:s');
                    $status_changed = false;
                    $user_changed = false;
                    if ($new) {
                        // New conversation
                        $customer = Customer::create($to_array[0]);

                        $conversation = new Conversation();
                        $conversation->type = Conversation::TYPE_EMAIL;
                        $conversation->status = $request->status;
                        $conversation->state = Conversation::STATE_PUBLISHED;
                        $conversation->subject = $request->subject;
                        $conversation->setCc($request->cc);
                        $conversation->setBcc($request->bcc);
                        $conversation->setPreview($request->body);
                        if ($has_attachments) {
                            $conversation->has_attachments = true;
                        }
                        // Set folder id
                        $conversation->mailbox_id = $request->mailbox_id;
                        $conversation->customer_id = $customer->id;
                        $conversation->created_by_user_id = auth()->user()->id;
                        $conversation->source_via = Conversation::PERSON_USER;
                        $conversation->source_type = Conversation::SOURCE_TYPE_WEB;
                    } else {
                        $customer = $conversation->customer;

                        if ((int) $request->status != (int) $conversation->status) {
                            $status_changed = true;
                        }
                    }
                    $conversation->status = $request->status;
                    if ((int) $request->user_id != -1) {
                        // Check if user has access to the current mailbox
                        if ((int) $conversation->user_id != (int) $request->user_id && $mailbox->userHasAccess($request->user_id)) {
                            $conversation->user_id = $request->user_id;
                            $user_changed = true;
                        }
                    } else {
                        $conversation->user_id = null;
                    }
                    $conversation->last_reply_at = $now;
                    $conversation->last_reply_from = Conversation::PERSON_USER;
                    $conversation->user_updated_at = $now;
                    $conversation->updateFolder();
                    $conversation->save();

                    // Fire events
                    if (!$new) {
                        if ($status_changed) {
                            event(new ConversationStatusChanged($conversation));
                        }
                        if ($user_changed) {
                            event(new ConversationUserChanged($conversation, $user));
                        }
                    }

                    // Create thread
                    $thread = new Thread();
                    $thread->conversation_id = $conversation->id;
                    $thread->user_id = auth()->user()->id;
                    $thread->type = Thread::TYPE_MESSAGE;
                    $thread->status = $request->status;
                    $thread->state = Thread::STATE_PUBLISHED;
                    $thread->body = $request->body;
                    $thread->setTo($request->to);
                    $thread->setCc($request->cc);
                    $thread->setBcc($request->bcc);
                    $thread->source_via = Thread::PERSON_USER;
                    $thread->source_type = Thread::SOURCE_TYPE_WEB;
                    $thread->customer_id = $customer->id;
                    $thread->created_by_user_id = auth()->user()->id;
                    if ($has_attachments) {
                        $thread->has_attachments = true;
                    }
                    $thread->save();

                    $response['status'] = 'success';

                    // Set thread_id for uploaded attachments
                    if ($attachments) {
                        Attachment::whereIn('id', $attachments)->update(['thread_id' => $thread->id]);
                    }

                    if ($new) {
                        event(new UserCreatedConversation($conversation, $thread));
                    } else {
                        event(new UserReplied($conversation, $thread));
                    }

                    // Determine redirect
                    if (!empty($request->after_send)) {
                        switch ($request->after_send) {
                            case MailboxUser::AFTER_SEND_STAY:
                            default:
                                $response['redirect_url'] = $conversation->url();
                                break;
                            case MailboxUser::AFTER_SEND_FOLDER:
                                $response['redirect_url'] = route('mailboxes.view.folder', ['id' => $conversation->mailbox_id, 'folder_id' => $conversation->folder_id]);
                                break;
                            case MailboxUser::AFTER_SEND_NEXT:
                                $next_conversation = $conversation->getNearby();
                                if ($next_conversation) {
                                    $response['redirect_url'] = $next_conversation->url();
                                } else {
                                    // Show folder
                                    $response['redirect_url'] = route('mailboxes.view.folder', ['id' => $conversation->mailbox_id, 'folder_id' => Conversation::getCurrentFolder($conversation->folder_id)]);
                                }
                                break;
                        }
                    } else {
                        // If something went wrong and after_send not set, just show the reply
                        $response['redirect_url'] = $conversation->url();
                    }

                    if (!empty($request->after_send) && $request->after_send == MailboxUser::AFTER_SEND_STAY) {
                        // Message without View link
                        $flash_message = __(
                            ':%tag_start%Email Sent:%tag_end% :%undo_start%Undo:%a_end%',
                            ['%tag_start%' => '<strong>', '%tag_end%' => '</strong>', '%view_start%' => '&nbsp;<a href="'.$conversation->url().'">', '%a_end%' => '</a>&nbsp;', '%undo_start%' => '&nbsp;<a href="'.route('conversations.draft', ['id' => $conversation->id]).'" class="text-danger">']
                        );
                    } else {
                        $flash_message = __(
                            ':%tag_start%Email Sent:%tag_end% :%view_start%View:%a_end% or :%undo_start%Undo:%a_end%',
                            ['%tag_start%' => '<strong>', '%tag_end%' => '</strong>', '%view_start%' => '&nbsp;<a href="'.$conversation->url().'">', '%a_end%' => '</a>&nbsp;', '%undo_start%' => '&nbsp;<a href="'.route('conversations.draft', ['id' => $conversation->id]).'" class="text-danger">']
                        );
                    }

                    \Session::flash('flash_success_floating', $flash_message);
                }
                break;

            // Save default redirect
            case 'save_after_send':
                $mailbox = Mailbox::find($request->mailbox_id);
                if (!$mailbox) {
                    $response['msg'] .= __('Mailbox not found');
                } elseif (!$mailbox->userHasAccess($user->id)) {
                    $response['msg'] .= __('Action not authorized');
                }
                if (!$response['msg']) {
                    $mailbox_user = $user->mailboxes()->where('mailbox_id', $request->mailbox_id)->first();
                    if (!$mailbox_user) {
                        $mailbox_user = new MailboxUser();
                        $mailbox_user->mailbox_id = $mailbox->id;
                        $mailbox_user->user_id = $user->id;
                    }
                    $mailbox_user->settings->after_send = $request->value;
                    $mailbox_user->settings->save();

                    $response['status'] = 'success';
                }
                break;
            default:
                $response['msg'] = 'Unknown action';
                break;
        }

        if ($response['status'] == 'error' && empty($response['msg'])) {
            $response['msg'] = 'Unknown error occured';
        }

        return \Response::json($response);
    }

    /**
     * Upload files and images.
     */
    public function upload(Request $request)
    {
        $response = [
            'status' => 'error',
            'msg'    => '', // this is error message
        ];

        $user = auth()->user();

        if (!$user) {
            $response['msg'] = __('Please login to upload file');
        }

        if (!$request->hasFile('file') || !$request->file('file')->isValid() || !$request->file) {
            $response['msg'] = __('Error occured uploading file');
        }

        if (!$response['msg']) {
            $embedded = true;

            if (!empty($request->attach) && (int)$request->attach) {
                $embedded = false;
            }

            $attachment = Attachment::create(
                $request->file->getClientOriginalName(),
                $request->file->getMimeType(),
                null,
                '',
                $request->file,
                $embedded,
                null,
                $user->id
            );

            if ($attachment) {
                $response['status'] = 'success';
                $response['url'] = $attachment->url();
                $response['attachment_id'] = $attachment->id;
            } else {
                $response['msg'] = __('Error occured uploading file');
            }
        }
        return \Response::json($response);
    }
}
