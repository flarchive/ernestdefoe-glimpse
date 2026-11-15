import app from 'flarum/forum/app';
import FormModal, { type IFormModalAttrs } from 'flarum/common/components/FormModal';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import classList from 'flarum/common/utils/classList';
import type Mithril from 'mithril';
import type Story from '../models/Story';
import type { ApiPayloadSingle } from 'flarum/common/Store';
import type StoryMedia from '../models/StoryMedia';
import type { Audience, Kind } from '../models/Story';
import { has, storiesChanged, t, tx } from '../util';

const BACKGROUNDS = 8;
const TEXT_MAX = 200;
const CAPTION_MAX = 200;

/**
 * The create sheet: a photo, a short video, or text on a colour; a caption with @mentions, one link,
 * and who can see it. The file is uploaded as soon as it is picked, so the preview is the server's
 * cleaned copy and Share only has to send the story.
 */
export default class CreateModal extends FormModal<IFormModalAttrs> {
  private kind: Kind = 'photo';
  private media: StoryMedia | null = null;
  private uploading = false;
  private uploadError: string | null = null;
  private text = '';
  private background = 0;
  private caption = '';
  private link = '';
  private audience: Audience = 'everyone';
  private alsoMoments = false;
  private file: File | null = null;

  oninit(vnode: Mithril.Vnode<IFormModalAttrs, this>) {
    super.oninit(vnode);
    this.kind = this.kinds()[0] || 'text';
    this.audience = (app.forum.attribute<Audience>('glimpseDefaultAudience') || 'everyone') as Audience;
    if (this.audience === 'followers' && !app.forum.attribute('glimpseFollows')) this.audience = 'everyone';
  }

  className() {
    return 'GlimpseCreate';
  }

  title() {
    return t('create.title');
  }

  kinds(): Kind[] {
    return (['photo', 'video', 'text'] as Kind[]).filter((kind) => app.forum.attribute(`glimpseKind${kind[0].toUpperCase()}${kind.slice(1)}`));
  }

  content() {
    return (
      <div className="Modal-body GlimpseCreate-body">
        <div className={classList('GlimpseCreate-preview', this.kind === 'text' && `GlimpseText GlimpseBg-${this.background}`)} aria-hidden="true">
          {this.preview()}
        </div>
        <div className="GlimpseCreate-fields">
          {this.kindTabs()}
          {this.kind === 'text' ? this.textFields() : this.fileField()}
          {this.captionField()}
          {this.linkField()}
          {this.audienceField()}
          {this.momentsField()}
        </div>
        <div className="GlimpseCreate-foot">
          <span className="GlimpseCreate-lifetime">{t('create.lifetime', { count: app.forum.attribute('glimpseLifetimeHours') || 24 })}</span>
          <Button type="submit" className="Button Button--primary" loading={this.loading} disabled={!this.ready()}>
            {t('create.share')}
          </Button>
        </div>
      </div>
    );
  }

  /** Photo, video or text: only the kinds the admin turned on. */
  private kindTabs(): Mithril.Children {
    return (
      <div className="GlimpseCreate-kinds" role="tablist" aria-label={tx('create.kind_label')}>
        {this.kinds().map((kind) => (
          <button
            type="button"
            role="tab"
            aria-selected={this.kind === kind ? 'true' : 'false'}
            className={classList('Button', this.kind === kind && 'is-active')}
            onclick={() => this.pick(kind)}
          >
            <i className={classList('fas', KIND_ICONS[kind])} aria-hidden="true" /> {t(`create.kinds.${kind}`)}
          </button>
        ))}
      </div>
    );
  }

  /** The caption, with what is left of its length. */
  private captionField(): Mithril.Children {
    return (
      <div className="Form-group">
        <label for="glimpse-caption">{t('create.caption')}</label>
        <textarea
          id="glimpse-caption"
          name="caption"
          className="FormControl"
          rows={2}
          maxlength={CAPTION_MAX}
          value={this.caption}
          oninput={(e: InputEvent) => {
            this.caption = (e.target as HTMLTextAreaElement).value;
          }}
        />
        <p className="helpText">{t('create.caption_help', { count: CAPTION_MAX - this.caption.length })}</p>
      </div>
    );
  }

  /** One optional link, shown as a button on the story. */
  private linkField(): Mithril.Children {
    return (
      <div className="Form-group">
        <label for="glimpse-link">{t('create.link')}</label>
        <input
          id="glimpse-link"
          name="linkUrl"
          type="url"
          className="FormControl"
          placeholder="https://"
          maxlength={2000}
          value={this.link}
          oninput={(e: InputEvent) => {
            this.link = (e.target as HTMLInputElement).value;
          }}
        />
      </div>
    );
  }

  /** Who can see it: Followers only with a follow extension. */
  private audienceField(): Mithril.Children {
    return (
      <fieldset className="Form-group GlimpseCreate-audience">
        <legend>{t('create.audience')}</legend>
        <div className="GlimpseCreate-chips">
          {this.audiences().map((audience) => (
            <label className={classList('GlimpseChip', this.audience === audience && 'is-active')}>
              <input
                type="radio"
                name="audience"
                value={audience}
                checked={this.audience === audience}
                onchange={() => {
                  this.audience = audience;
                }}
              />
              <i className={classList('fas', AUDIENCE_ICONS[audience])} aria-hidden="true" /> {t(`audience.${audience}`)}
            </label>
          ))}
        </div>
      </fieldset>
    );
  }

  /** "Also post to Moments", for photos and videos everyone may see, when Moments is installed. */
  private momentsField(): Mithril.Children {
    if (!(has('ernestdefoe-moments') && this.kind !== 'text')) return null;

    return (
      <label className="checkbox GlimpseCreate-moments">
        <input
          type="checkbox"
          checked={this.alsoMoments}
          disabled={this.audience !== 'everyone'}
          onchange={(e: Event) => {
            this.alsoMoments = (e.target as HTMLInputElement).checked;
          }}
        />
        {t('create.also_moments')}
      </label>
    );
  }

  private audiences(): Audience[] {
    return app.forum.attribute('glimpseFollows') ? ['everyone', 'members', 'followers'] : ['everyone', 'members'];
  }

  private textFields(): Mithril.Children {
    return [
      <div className="Form-group">
        <label for="glimpse-text">{t('create.text')}</label>
        <textarea
          id="glimpse-text"
          name="text"
          className="FormControl"
          rows={3}
          maxlength={TEXT_MAX}
          value={this.text}
          oninput={(e: InputEvent) => {
            this.text = (e.target as HTMLTextAreaElement).value;
          }}
        />
      </div>,
      <div className="GlimpseCreate-swatches" role="radiogroup" aria-label={tx('create.background')}>
        {Array.from({ length: BACKGROUNDS }, (_, i) => (
          <button
            type="button"
            role="radio"
            aria-checked={this.background === i ? 'true' : 'false'}
            aria-label={tx('create.background_n', { number: i + 1 })}
            className={classList('GlimpseCreate-swatch', `GlimpseBg-${i}`, this.background === i && 'is-active')}
            onclick={() => {
              this.background = i;
            }}
          />
        ))}
      </div>,
    ];
  }

  private fileField(): Mithril.Children {
    const video = this.kind === 'video';
    const mb = Math.round((Number(app.forum.attribute(video ? 'glimpseVideoMaxBytes' : 'glimpseImageMaxBytes')) || 0) / 1048576);

    return (
      <div className="Form-group">
        <label className="GlimpseCreate-drop">
          <input
            type="file"
            name="file"
            className="GlimpseCreate-file"
            accept={video ? 'video/mp4,video/webm,video/quicktime' : 'image/jpeg,image/png,image/gif,image/webp'}
            onchange={(e: Event) => this.upload((e.target as HTMLInputElement).files?.[0] || null)}
          />
          <i className={classList('fas', video ? 'fa-film' : 'fa-image')} aria-hidden="true" />{' '}
          {this.file ? <strong>{this.file.name}</strong> : t(video ? 'create.pick_video' : 'create.pick_photo')}
          <small>
            {video ? t('create.video_help', { mb, seconds: app.forum.attribute('glimpseVideoMaxSeconds') || 15 }) : t('create.photo_help', { mb })}
          </small>
        </label>
        {this.uploading ? <LoadingIndicator size="small" display="inline" /> : null}
        {this.uploadError ? (
          <p className="GlimpseCreate-error" role="alert">
            {this.uploadError}
          </p>
        ) : null}
      </div>
    );
  }

  private preview(): Mithril.Children {
    if (this.kind === 'text') {
      return <p className={classList('GlimpseText-body', this.text.length > 90 && 'is-long')}>{this.text || tx('create.text_placeholder')}</p>;
    }
    if (!this.media) {
      return <i className={classList('fas GlimpseCreate-placeholder', this.kind === 'video' ? 'fa-film' : 'fa-image')} />;
    }
    return this.media.kind() === 'video' ? <video src={this.media.url()} muted autoplay loop playsinline /> : <img src={this.media.url()} alt="" />;
  }

  private pick(kind: Kind): void {
    if (kind === this.kind) return;
    this.kind = kind;
    this.media = null;
    this.file = null;
    this.uploadError = null;
  }

  private ready(): boolean {
    if (this.uploading) return false;
    return this.kind === 'text' ? this.text.trim().length > 0 : !!this.media;
  }

  /** Checks what the browser can, then uploads; the server checks everything again. */
  private upload(file: File | null): void {
    this.media = null;
    this.uploadError = null;
    this.file = file;
    if (!file) return;

    const max = Number(app.forum.attribute(this.kind === 'video' ? 'glimpseVideoMaxBytes' : 'glimpseImageMaxBytes')) || Infinity;
    if (file.size > max) {
      this.uploadError = tx('create.too_large', { mb: Math.round(max / 1048576) });
      return;
    }

    const body = new FormData();
    body.append('file', file);
    this.uploading = true;
    app
      .request<ApiPayloadSingle>({
        method: 'POST',
        url: `${app.forum.attribute('apiUrl')}/glimpse-media`,
        serialize: (raw: FormData) => raw,
        body,
        errorHandler: () => {},
      })
      .then((payload) => {
        if (this.file !== file) return;
        this.media = app.store.pushPayload<StoryMedia>(payload) as StoryMedia;
      })
      .catch((error: { response?: { errors?: Array<{ detail?: string }> } }) => {
        this.uploadError = error?.response?.errors?.[0]?.detail || tx('create.upload_failed');
      })
      .finally(() => {
        this.uploading = false;
        m.redraw();
      });
  }

  onsubmit(e: SubmitEvent): void {
    e.preventDefault();
    if (!this.ready()) return;
    this.loading = true;

    const data: Record<string, unknown> = {
      kind: this.kind,
      caption: this.caption.trim() || null,
      linkUrl: this.link.trim() || null,
      audience: this.audience,
    };
    if (this.kind === 'text') {
      data.text = this.text.trim();
      data.background = this.background;
    } else {
      data.relationships = { media: this.media };
    }

    app.store
      .createRecord<Story>('glimpse-stories')
      .save(data)
      .then((story) => {
        if (this.alsoMoments && this.file && this.audience === 'everyone') this.crossPost(this.file);
        this.hide();
        storiesChanged();
        app.alerts.show({ type: 'success' }, tx(story.status() === 'pending' ? 'create.pending' : 'create.shared'));
      }, this.loaded.bind(this));
  }

  /** "Also post to Moments": the same file and caption through Moments' own upload and post endpoints. */
  private crossPost(file: File): void {
    const api = app.forum.attribute('apiUrl');
    const body = new FormData();
    body.append('file', file);
    body.append('purpose', 'media');
    app
      .request<{ data?: { id?: number } }>({
        method: 'POST',
        url: `${api}/moments-media`,
        serialize: (raw: FormData) => raw,
        body,
        errorHandler: () => {},
      })
      .then((uploaded) => {
        const id = uploaded?.data?.id;
        return app.request({
          method: 'POST',
          url: `${api}/moments`,
          body: { content: this.caption.trim(), media: id ? [id] : [] },
          errorHandler: () => {},
        });
      })
      .catch(() => app.alerts.show({ type: 'error' }, tx('create.moments_failed')));
  }
}

const KIND_ICONS: Record<Kind, string> = { photo: 'fa-image', video: 'fa-film', text: 'fa-font' };
const AUDIENCE_ICONS: Record<Audience, string> = { everyone: 'fa-globe', members: 'fa-user-check', followers: 'fa-user-group' };
