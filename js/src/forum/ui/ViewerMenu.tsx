import app from 'flarum/forum/app';
import Component, { type ComponentAttrs } from 'flarum/common/Component';
import classList from 'flarum/common/utils/classList';
import type User from 'flarum/common/models/User';
import type Story from '../models/Story';
import { failed, storiesChanged, t, tx } from '../util';
import { openHighlightPicker, openReport } from './modals';

interface ViewerMenuAttrs extends ComponentAttrs {
  story: Story;
  own: boolean;
  onsay: (message: string) => void;
  /** The menu closed; resume playback unless a modal took over. */
  onclose: (resume: boolean) => void;
  onmuted: () => void;
  ondeleted: () => void;
}

type Action = 'highlight' | 'report' | 'mute' | 'copy' | 'hide' | 'delete';

/** The viewer's ⋯ menu: what the viewer may do to this story, nothing they may not. */
export default class ViewerMenu extends Component<ViewerMenuAttrs> {
  view() {
    const { story, own } = this.attrs;
    const items: Array<[Action, string, boolean?]> = [];
    if (own) items.push(['highlight', 'fa-star']);
    if (story.canReport()) items.push(['report', 'fa-flag']);
    if (!own && app.session.user) items.push(['mute', 'fa-volume-xmark']);
    items.push(['copy', 'fa-link']);
    if (story.canModerate() && !own) items.push(['hide', 'fa-eye-slash', true]);
    if (story.canDelete()) items.push(['delete', 'fa-trash', true]);

    return (
      <ul className="GlimpseViewer-menu" role="menu">
        {items.map(([action, icon, danger]) => (
          <li role="none">
            <button type="button" role="menuitem" className={classList(danger && 'is-danger')} onclick={() => this.run(action)}>
              <i className={classList('fas', icon)} aria-hidden="true" />{' '}
              {t(`viewer.menu.${action}`, { name: (story.user() || null)?.displayName() })}
            </button>
          </li>
        ))}
      </ul>
    );
  }

  private run(action: Action): void {
    const { story, onsay, onclose } = this.attrs;
    const user = story.user() || null;
    const resume = () => onclose(true);

    if (action === 'report') {
      onclose(false);
      return openReport(story, () => onsay(tx('viewer.reported')), resume);
    }
    if (action === 'highlight') {
      onclose(false);
      return openHighlightPicker(story, (title) => onsay(tx('viewer.highlighted', { title })), resume);
    }
    if (action === 'mute' && user) return this.mute(user);
    if (action === 'copy') return this.copyLink();
    if (action === 'hide') return this.hide();
    if (action === 'delete') return this.remove();
  }

  private mute(user: User): void {
    this.attrs.onclose(false);
    app.store
      .createRecord('glimpse-mutes')
      .save({ relationships: { mutedUser: user } })
      .then(() => {
        this.attrs.onsay(tx('viewer.muted', { name: user.displayName() }));
        storiesChanged();
        window.setTimeout(() => this.attrs.onmuted(), 700);
      }, failed);
  }

  private copyLink(): void {
    navigator.clipboard
      ?.writeText(app.forum.attribute<string>('baseUrl') + app.route('glimpse.story', { id: this.attrs.story.id() }))
      .then(() => this.attrs.onsay(tx('viewer.copied')))
      .catch(() => this.attrs.onsay(tx('viewer.copy_failed')));
    this.attrs.onclose(true);
  }

  private hide(): void {
    this.attrs.story.save({ status: 'hidden' }).then(() => {
      this.attrs.onsay(tx('viewer.hidden'));
      storiesChanged();
      this.attrs.onclose(true);
    }, failed);
  }

  private remove(): void {
    if (!confirm(tx('viewer.delete_confirm'))) return this.attrs.onclose(true);
    this.attrs.onclose(false);
    this.attrs.story.delete().then(() => {
      this.attrs.onsay(tx('viewer.deleted'));
      storiesChanged();
      this.attrs.ondeleted();
    }, failed);
  }
}
