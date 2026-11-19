import app from 'flarum/forum/app';
import Modal, { type IInternalModalAttrs } from 'flarum/common/components/Modal';
import FormModal, { type IFormModalAttrs } from 'flarum/common/components/FormModal';
import Avatar from 'flarum/common/components/Avatar';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Link from 'flarum/common/components/Link';
import classList from 'flarum/common/utils/classList';
import humanTime from 'flarum/common/helpers/humanTime';
import type Mithril from 'mithril';
import type Story from '../models/Story';
import type StoryHighlight from '../models/StoryHighlight';
import type StoryView from '../models/StoryView';
import { failed, t, tx } from '../util';

interface ClosingAttrs {
  onclose?: () => void;
}

const PAGE = 20;

interface SeenByAttrs extends IInternalModalAttrs, ClosingAttrs {
  story: Story;
}

/** Who saw a story and how they reacted. Its author and moderators only; the server enforces it. */
export class SeenByModal extends Modal<SeenByAttrs> {
  private views: StoryView[] = [];
  private busy = true;
  private more = false;

  oninit(vnode: Mithril.Vnode<SeenByAttrs, this>) {
    super.oninit(vnode);
    this.load(0);
  }

  onremove(vnode: Mithril.VnodeDOM<SeenByAttrs, this>) {
    super.onremove(vnode);
    this.attrs.onclose?.();
  }

  className() {
    return 'GlimpseSeenBy Modal--small';
  }

  title() {
    return t('seen_by.title');
  }

  load(offset: number): void {
    this.busy = true;
    app.store
      .find<StoryView[]>('glimpse-views', { filter: { story: this.attrs.story.id() }, page: { offset, limit: PAGE, total: 0 } })
      .then((views) => {
        this.views = offset ? [...this.views, ...views] : [...views];
        this.more = !!views.payload?.links?.next;
      })
      .catch(failed)
      .finally(() => {
        this.busy = false;
        m.redraw();
      });
  }

  content() {
    const story = this.attrs.story;

    return (
      <div className="Modal-body">
        <p className="GlimpseSeenBy-summary">
          <span>
            <i className="fas fa-eye" aria-hidden="true" /> {t('viewer.seen_by', { count: story.viewCount() || 0 })}
          </span>
          <span>
            <i className="fas fa-heart" aria-hidden="true" /> {t('viewer.reactions', { count: story.reactionCount() || 0 })}
          </span>
          <span className="GlimpseSeenBy-private">{t('seen_by.private')}</span>
        </p>
        {this.views.length ? (
          <ul className="GlimpseSeenBy-list">
            {this.views.map((view) => {
              const user = view.user();
              return user ? (
                <li>
                  <Link href={app.route.user(user)} className="GlimpseSeenBy-user" onclick={() => app.modal.close()}>
                    <Avatar user={user} />
                    <span className="GlimpseSeenBy-name">{user.displayName()}</span>
                  </Link>
                  <span className="GlimpseSeenBy-time">{humanTime(view.viewedAt())}</span>
                  <span
                    className="GlimpseSeenBy-reaction"
                    aria-label={view.reaction() ? tx('seen_by.reacted', { reaction: view.reaction() }) : undefined}
                  >
                    {view.reaction()}
                  </span>
                </li>
              ) : null;
            })}
          </ul>
        ) : this.busy ? null : (
          <p className="GlimpseSeenBy-empty">{t('seen_by.empty')}</p>
        )}
        {this.busy ? <LoadingIndicator /> : null}
        {this.more && !this.busy ? (
          <Button className="Button Button--block" onclick={() => this.load(this.views.length)}>
            {t('seen_by.more')}
          </Button>
        ) : null}
      </div>
    );
  }
}

interface ReportAttrs extends IFormModalAttrs, ClosingAttrs {
  story: Story;
  onreported: () => void;
}

const REASONS = ['spam', 'inappropriate', 'harassment', 'other'];

/** Report a story to the moderators: a reason, and a few words if the member wants. */
export class ReportModal extends FormModal<ReportAttrs> {
  private reason = '';
  private detail = '';

  onremove(vnode: Mithril.VnodeDOM<ReportAttrs, this>) {
    super.onremove(vnode);
    this.attrs.onclose?.();
  }

  className() {
    return 'GlimpseReport Modal--small';
  }

  title() {
    return t('report.title');
  }

  content() {
    return (
      <div className="Modal-body">
        <fieldset className="Form-group GlimpseReport-reasons">
          <legend>{t('report.reason')}</legend>
          {REASONS.map((reason) => (
            <label className="checkbox">
              <input
                type="radio"
                name="reason"
                value={reason}
                checked={this.reason === reason}
                onchange={() => {
                  this.reason = reason;
                }}
              />
              {t(`report.reasons.${reason}`)}
            </label>
          ))}
        </fieldset>
        <div className="Form-group">
          <label for="glimpse-report-detail">{t('report.detail')}</label>
          <textarea
            id="glimpse-report-detail"
            name="detail"
            className="FormControl"
            maxlength={500}
            rows={3}
            value={this.detail}
            oninput={(e: InputEvent) => {
              this.detail = (e.target as HTMLTextAreaElement).value;
            }}
          />
        </div>
        <Button type="submit" className="Button Button--primary Button--block" loading={this.loading} disabled={!this.reason}>
          {t('report.submit')}
        </Button>
      </div>
    );
  }

  onsubmit(e: SubmitEvent): void {
    e.preventDefault();
    this.loading = true;
    app.store
      .createRecord('glimpse-reports')
      .save({ reason: this.reason, detail: this.detail.trim() || null, relationships: { story: this.attrs.story } })
      .then(() => {
        this.hide();
        this.attrs.onreported();
      }, this.loaded.bind(this));
  }
}

interface HighlightPickerAttrs extends IFormModalAttrs, ClosingAttrs {
  story: Story;
  onadded: (title: string) => void;
}

/** "Add to highlight": one of your highlights, or a new one. */
export class HighlightPickerModal extends FormModal<HighlightPickerAttrs> {
  private highlights: StoryHighlight[] | null = null;
  private name = '';

  oninit(vnode: Mithril.Vnode<HighlightPickerAttrs, this>) {
    super.oninit(vnode);
    app.store
      .find<StoryHighlight[]>('glimpse-highlights', { filter: { user: app.session.user!.id() } })
      .then((highlights) => (this.highlights = [...(highlights as StoryHighlight[])]))
      .catch(() => {
        this.highlights = [];
        failed();
      })
      .finally(() => m.redraw());
  }

  onremove(vnode: Mithril.VnodeDOM<HighlightPickerAttrs, this>) {
    super.onremove(vnode);
    this.attrs.onclose?.();
  }

  className() {
    return 'GlimpseHighlightPicker Modal--small';
  }

  title() {
    return t('highlights.add_title');
  }

  content() {
    const story = this.attrs.story;

    return (
      <div className="Modal-body">
        {this.highlights === null ? <LoadingIndicator /> : null}
        {this.highlights?.length ? (
          <ul className="GlimpseHighlightPicker-list">
            {this.highlights.map((highlight) => {
              const has = (highlight.stories() || []).includes(story);
              return (
                <li>
                  <Button
                    className={classList('Button', has && 'Button--primary')}
                    icon={has ? 'fas fa-check' : 'fas fa-plus'}
                    disabled={has || this.loading}
                    onclick={() => this.addTo(highlight)}
                  >
                    {highlight.title()}
                  </Button>
                </li>
              );
            })}
          </ul>
        ) : null}
        <div className="Form-group">
          <label for="glimpse-highlight-title">{t('highlights.new_label')}</label>
          <input
            id="glimpse-highlight-title"
            name="title"
            className="FormControl"
            maxlength={40}
            placeholder={tx('highlights.new_placeholder')}
            value={this.name}
            oninput={(e: InputEvent) => {
              this.name = (e.target as HTMLInputElement).value;
            }}
          />
        </div>
        <Button type="submit" className="Button Button--primary Button--block" loading={this.loading} disabled={!this.name.trim()}>
          {t('highlights.create')}
        </Button>
      </div>
    );
  }

  addTo(highlight: StoryHighlight): void {
    this.loading = true;
    const stories = (highlight.stories() || []).filter((story): story is Story => !!story);
    highlight.save({ relationships: { stories: [...stories, this.attrs.story] } }).then(() => {
      this.hide();
      this.attrs.onadded(highlight.title());
    }, this.loaded.bind(this));
  }

  onsubmit(e: SubmitEvent): void {
    e.preventDefault();
    const title = this.name.trim();
    if (!title) return;
    this.loading = true;
    app.store
      .createRecord('glimpse-highlights')
      .save({ title, relationships: { stories: [this.attrs.story] } })
      .then(() => {
        this.hide();
        this.attrs.onadded(title);
      }, this.loaded.bind(this));
  }
}

export function openSeenBy(story: Story, onclose?: () => void): void {
  app.modal.show(SeenByModal, { story, onclose });
}

export function openReport(story: Story, onreported: () => void, onclose?: () => void): void {
  app.modal.show(ReportModal, { story, onreported, onclose });
}

export function openHighlightPicker(story: Story, onadded: (title: string) => void, onclose?: () => void): void {
  app.modal.show(HighlightPickerModal, { story, onadded, onclose });
}
