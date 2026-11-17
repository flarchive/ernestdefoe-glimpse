import app from 'flarum/forum/app';
import type Story from '../models/Story';
import { has, tx } from '../util';

/**
 * Where a reply to a story goes: a private message to its author, through Parley or Flarum's own
 * Messages, whichever the admin picked (or found first). Each one is that extension's own API, so its
 * permissions, blocks and limits apply as if the member had written the message there.
 */
export function replyChannel(): 'parley' | 'messages' | null {
  const choice = app.forum.attribute<string>('glimpseReplyVia');
  if (choice === 'off') return null;
  if ((choice === 'parley' || choice === 'auto') && has('ernestdefoe-parley')) return 'parley';
  if ((choice === 'messages' || choice === 'auto') && has('flarum-messages')) return 'messages';
  return null;
}

export async function sendReply(story: Story, text: string): Promise<void> {
  const author = story.user();
  const channel = replyChannel();
  if (!author || !channel) return;

  const url = app.forum.attribute<string>('baseUrl') + app.route('glimpse.story', { id: story.id() });
  const content = `${tx('viewer.reply_prefix')} ${url}\n\n${text}`;

  if (channel === 'messages') {
    await app.store.createRecord('dialog-messages').save({ content, users: [{ id: author.id() }] });
    return;
  }

  // Parley has no JSON:API type for this, only its own endpoints: open the conversation, then send.
  const opened = await app.request<{ conversation?: { id?: number } }>({
    method: 'POST',
    url: `${app.forum.attribute('apiUrl')}/parley/conversations`,
    body: { userId: author.id() },
  });
  const id = opened?.conversation?.id;
  if (typeof id !== 'number') throw new Error('Parley did not open a conversation.');
  await app.request({ method: 'POST', url: `${app.forum.attribute('apiUrl')}/parley/conversations/${id}/messages`, body: { body: content } });
}
