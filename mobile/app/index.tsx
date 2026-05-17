import { Redirect } from 'expo-router';
import { useAuthStore } from '@/lib/auth-store';

export default function Index() {
  const token = useAuthStore((s) => s.token);
  const user  = useAuthStore((s) => s.user);

  if (!token) {
    return <Redirect href="/(auth)/login" />;
  }

  // Se l'utente è loggato ma non ha ancora completato l'onboarding app, mandalo lì.
  if (user && !user.app_onboarded_at) {
    return <Redirect href="/onboarding" />;
  }

  return <Redirect href="/(tabs)" />;
}
