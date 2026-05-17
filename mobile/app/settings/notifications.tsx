import { useState } from 'react';
import { Alert, Pressable, ScrollView, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { router } from 'expo-router';
import { ChevronLeft } from 'lucide-react-native';

import { me } from '@/lib/api';
import { useAuthStore } from '@/lib/auth-store';

const DEFAULT_PREFS = {
  reminders: true,
  match_invites: true,
  results_request: true,
  chat: true,
  social: true,
  tournaments: true,
  marketing: false,
};

const LABELS: Record<string, { title: string; sub: string }> = {
  reminders:       { title: 'Promemoria partite',  sub: 'Avvisi prima delle tue prenotazioni' },
  match_invites:   { title: 'Inviti partita',      sub: 'Quando qualcuno ti propone un match' },
  results_request: { title: 'Richieste risultato', sub: 'Dopo la partita per registrare lo score' },
  chat:            { title: 'Chat',                sub: 'Nuovi messaggi dai tuoi contatti' },
  social:          { title: 'Attività sociale',    sub: 'Vittorie degli amici, nuovi follower' },
  tournaments:     { title: 'Tornei',              sub: 'Iscrizioni aperte, turni del tuo torneo' },
  marketing:       { title: 'Promozioni',          sub: 'Offerte speciali del circolo' },
};

export default function NotificationsSettings() {
  const user = useAuthStore((s) => s.user);
  const setUser = useAuthStore((s) => s.setUser);

  const initial = { ...DEFAULT_PREFS, ...(user?.notification_preferences ?? {}) };
  const [prefs, setPrefs] = useState(initial);

  const toggle = async (key: string) => {
    const next = { ...prefs, [key]: !prefs[key as keyof typeof prefs] };
    setPrefs(next);
    try {
      await me.updateNotificationPreferences(next);
      if (user) setUser({ ...user, notification_preferences: next });
    } catch {
      setPrefs(prefs); // rollback
      Alert.alert('Errore', 'Salvataggio fallito.');
    }
  };

  return (
    <SafeAreaView className="flex-1 bg-cream-light dark:bg-dark-bg" edges={['top']}>
      <View className="flex-row items-center justify-between px-5 pt-3 pb-2">
        <Pressable onPress={() => router.back()} className="w-10 h-10 items-center justify-center -ml-1">
          <ChevronLeft size={26} color="#1F2419" strokeWidth={1.5} />
        </Pressable>
        <Text className="font-display-italic text-[18px] text-ink dark:text-cream">Notifiche</Text>
        <View className="w-10" />
      </View>

      <ScrollView contentContainerStyle={{ paddingBottom: 40 }}>
        <Text className="font-body-medium text-[13px] text-ink-muted px-6 pt-2 pb-4 leading-relaxed">
          Scegli cosa vuoi ricevere come push. Quando avrai disattivato qualcosa, te lo manderemo via WhatsApp.
        </Text>

        <View className="mx-6 bg-white dark:bg-dark-surface rounded-3xl overflow-hidden">
          {Object.keys(LABELS).map((key, i) => (
            <View key={key}>
              {i > 0 && <Divider />}
              <ToggleRow
                title={LABELS[key].title}
                sub={LABELS[key].sub}
                value={!!prefs[key as keyof typeof prefs]}
                onToggle={() => toggle(key)}
              />
            </View>
          ))}
        </View>
      </ScrollView>
    </SafeAreaView>
  );
}

function ToggleRow({ title, sub, value, onToggle }: { title: string; sub: string; value: boolean; onToggle: () => void }) {
  return (
    <Pressable onPress={onToggle} className="flex-row items-center px-5 py-4 gap-3">
      <View className="flex-1">
        <Text className="font-body-semi text-[14px] text-ink dark:text-cream">{title}</Text>
        <Text className="font-body-medium text-[12px] text-ink-muted mt-0.5">{sub}</Text>
      </View>
      <View className={`w-12 h-7 rounded-full justify-center px-0.5 ${value ? 'bg-sage' : 'bg-divider'}`}>
        <View className={`w-6 h-6 rounded-full bg-white ${value ? 'self-end' : 'self-start'}`} style={{ shadowColor: '#000', shadowOpacity: 0.1, shadowRadius: 2 }} />
      </View>
    </Pressable>
  );
}

function Divider() {
  return <View style={{ height: 1, backgroundColor: 'rgba(31,36,25,0.06)', marginHorizontal: 20 }} />;
}
