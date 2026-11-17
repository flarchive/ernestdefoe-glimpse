import app from 'flarum/forum/app';
import Link from 'flarum/common/components/Link';
import type Mithril from 'mithril';
import type Story from '../models/Story';
import type User from 'flarum/common/models/User';

/**
 * A caption as text, with @username turned into a profile link only for the people the server
 * confirmed (the story's mentionedUsers). Nothing in it is ever rendered as HTML.
 */
export default function caption(story: Story): Mithril.Children {
  const text = story.caption();
  if (!text) return null;

  const users = new Map((story.mentionedUsers() || []).filter(Boolean).map((user) => [(user as User).username().toLowerCase(), user as User]));
  if (!users.size) return text;

  return text.split(/(@[A-Za-z0-9_-]{1,30})/g).map((part) => {
    const user = part.startsWith('@') ? users.get(part.slice(1).toLowerCase()) : null;
    return user ? <Link href={app.route.user(user)}>{part}</Link> : part;
  });
}
