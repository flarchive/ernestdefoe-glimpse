import app from 'flarum/forum/app';
import CreateModal from './CreateModal';

export function openCreate(): void {
  if (!app.session.user) {
    app.modal.show(() => import('flarum/forum/components/LogInModal'));
    return;
  }
  app.modal.show(CreateModal);
}
