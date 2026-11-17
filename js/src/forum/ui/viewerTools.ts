import type Story from '../models/Story';

export const AUDIENCE_ICONS: Record<string, string> = {
  everyone: 'fa-globe',
  members: 'fa-user-check',
  followers: 'fa-user-group',
};

/** A link's host, for the link button on a story ("example.com"). */
export function hostOf(url: string): string {
  try {
    return new URL(url).hostname.replace(/^www\./, '');
  } catch (e) {
    return url;
  }
}

/** Starts fetching a photo before it is shown (videos are preloaded by an element in the viewer). */
export function preload(story: Story): void {
  if (story.kind() === 'photo' && story.mediaUrl()) {
    const img = new Image();
    img.decoding = 'async';
    img.src = story.mediaUrl()!;
  }
}

/** Every seen-mark request still on its way; the bar reloads only once they are saved. */
let seenSaved: Promise<void> = Promise.resolve();

export function trackSeen(request: Promise<unknown>): void {
  seenSaved = Promise.all([seenSaved, request]).then(() => undefined);
}

export function whenSeenSaved(): Promise<void> {
  return seenSaved;
}
