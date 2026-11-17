import Avatar from 'flarum/common/components/Avatar';
import classList from 'flarum/common/utils/classList';
import humanTime from 'flarum/common/helpers/humanTime';
import type Mithril from 'mithril';
import type Story from '../models/Story';
import { t, tx } from '../util';

/** A 9:16 card: the photo, the first frame of a video, or the text on its colour, with the author. */
export function storyCard(story: Story, onopen: () => void, footer?: Mithril.Children): Mithril.Children {
  const user = story.user();
  return (
    <article className={classList('GlimpseCard', story.isSeen() && 'is-seen')}>
      <button type="button" className="GlimpseCard-open" onclick={onopen} aria-label={tx('page.open', { name: user ? user.displayName() : '' })}>
        <span className={classList('GlimpseCard-media', story.kind() === 'text' && `GlimpseText GlimpseBg-${story.background() ?? 0}`)}>
          {story.kind() === 'text' ? (
            <span className="GlimpseText-body">{story.text()}</span>
          ) : story.kind() === 'video' ? (
            <video src={`${story.mediaUrl()}#t=0.1`} preload="metadata" muted playsinline aria-hidden="true" />
          ) : (
            <img src={story.mediaUrl()!} alt="" loading="lazy" decoding="async" />
          )}
        </span>
        {story.kind() === 'video' && story.durationMs() ? (
          <span className="GlimpseCard-duration">
            <i className="fas fa-play" aria-hidden="true" /> {Math.round((story.durationMs() || 0) / 1000)}s
          </span>
        ) : null}
        {user ? (
          <span className="GlimpseCard-who">
            <Avatar user={user} />
            <span>
              <span className="GlimpseCard-name">{user.displayName()}</span>
              <span className="GlimpseCard-time">{humanTime(story.createdAt())}</span>
            </span>
          </span>
        ) : null}
      </button>
      {status(story) ? <p className="GlimpseCard-status">{status(story)}</p> : null}
      {footer ? <div className="GlimpseCard-foot">{footer}</div> : null}
    </article>
  );
}

/** What a story's author (or a moderator) needs to know that a viewer never sees. */
function status(story: Story): Mithril.Children {
  if (story.status() === 'pending') return t('profile.pending');
  if (story.status() === 'hidden') return t('profile.hidden');
  const expires = story.expiresAt();
  return expires && expires.getTime() < Date.now() ? t('profile.kept') : null;
}
