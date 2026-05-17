import { useState } from 'react';
import { ActivityIndicator, Alert, Pressable, ScrollView, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { router } from 'expo-router';
import { Bell, Lock, Sparkles } from 'lucide-react-native';

import { me } from '@/lib/api';
import { useAuthStore } from '@/lib/auth-store';
import { firstName } from '@/lib/format';

const DEFAULT_PREFS = {
  reminders: true,
  match_invites: true,
  results_request: true,
  chat: true,
  social: true,
  tournaments: true,
  marketing: false,
};

export default function Onboarding() {
  const user = useAuthStore((s) => s.user);
  const setUser = useAuthStore((s) => s.setUser);

  const [prefs, setPrefs] = useState(DEFAULT_PREFS);
  const [privacy, setPrivacy] = useState<'public' | 'club_only' | 'friends_only'>('club_only');
  const [accepted, setAccepted] = useState(false);
  const [saving, setSaving] = useState(false);

  const finish = async () => {
    if (!accepted) {
      Alert.alert('Termini', 'Devi accettare i termini per continuare.');
      return;
    }
    setSaving(true);
    try {
      await me.updateNotificationPreferences(prefs);
      const updated = await me.update({ privacy_profile: privacy });
      await me.completeOnboarding();
      if (user) {
        setUser({ ...updated, app_onboarded_at: new Date().toISOString() });
      }
      router.replace('/(tabs)');
    } catch {
      Alert.alert('Errore', 'Salvataggio fallito. Riprova.');
    } finally {
      setSaving(false);
    }
  };

  return (
    <SafeAreaView className="flex-1 bg-cream-light dark:bg-dark-bg" edges={['top']}>
      <ScrollView contentContainerStyle={{ paddingBottom: 40 }}>
        {/* Hero */}
        <View className="items-center px-7 pt-10 pb-8">
          <Text className="font-body-bold text-[10px] tracking-widest uppercase text-ink-muted">Benvenuto</Text>
          <Text className="font-script text-[56px] text-sage-dark dark:text-sage leading-[56px] mt-2">
            {firstName(user?.name)}
          </Text>
          <Text className="font-display-italic text-[22px] text-ink dark:text-cream text-center mt-3">
            Due dettagli e sei dentro.
          </Text>
        </View>

        {/* Notifications */}
        <Section
          icon={<Bell size={20} color="#A47A3F" strokeWidth={1.5} />}
          title="Cosa vuoi ricevere?"
          subtitle="Push sull'app. Puoi cambiarli sempre dalle impostazioni."
        >
          <Toggle label="Promemoria partite" value={prefs.reminders}     onToggle={() => setPrefs({ ...prefs, reminders: !prefs.reminders })} />
          <Toggle label="Inviti partita"     value={prefs.match_invites} onToggle={() => setPrefs({ ...prefs, match_invites: !prefs.match_invites })} />
          <Toggle label="Richieste risultato" value={prefs.results_request} onToggle={() => setPrefs({ ...prefs, results_request: !prefs.results_request })} />
          <Toggle label="Chat" value={prefs.chat} onToggle={() => setPrefs({ ...prefs, chat: !prefs.chat })} />
          <Toggle label="Tornei ed eventi" value={prefs.tournaments} onToggle={() => setPrefs({ ...prefs, tournaments: !prefs.tournaments, social: !prefs.tournaments })} />
          <Toggle label="Promozioni del circolo" value={prefs.marketing} onToggle={() => setPrefs({ ...prefs, marketing: !prefs.marketing })} />
        </Section>

        {/* Privacy */}
        <Section
          icon={<Lock size={20} color="#4F6450" strokeWidth={1.5} />}
          title="Chi può vederti?"
          subtitle="Decidi tu chi vede il tuo profilo e le tue statistiche."
        >
          <PrivacyOption label="Pubblico"   sub="Chiunque può vederti"    selected={privacy === 'public'}       onPress={() => setPrivacy('public')} />
          <PrivacyOption label="Solo soci"  sub="Solo membri di Le Cercle" selected={privacy === 'club_only'}    onPress={() => setPrivacy('club_only')} />
          <PrivacyOption label="Solo amici" sub="Solo i tuoi amici"        selected={privacy === 'friends_only'} onPress={() => setPrivacy('friends_only')} />
        </Section>

        {/* Terms */}
        <View className="px-6 mt-6">
          <Pressable onPress={() => setAccepted(!accepted)} className="flex-row items-start gap-3">
            <View className={`w-6 h-6 rounded-md mt-0.5 items-center justify-center ${accepted ? 'bg-sage' : 'border-2 border-divider'}`}>
              {accepted && <Text className="text-cream text-[14px]">✓</Text>}
            </View>
            <Text className="flex-1 font-body-medium text-[13px] text-ink dark:text-cream leading-relaxed">
              Ho letto e accetto i{' '}
              <Text className="text-sage-dark dark:text-sage underline">Termini di servizio</Text>{' '}
              e la{' '}
              <Text className="text-sage-dark dark:text-sage underline">Privacy Policy</Text>{' '}
              di Le Cercle Club.
            </Text>
          </Pressable>
        </View>

        {/* CTA */}
        <View className="px-6 mt-6">
          <Pressable
            disabled={!accepted || saving}
            onPress={finish}
            className={`w-full py-[17px] rounded-full items-center flex-row justify-center gap-2 ${accepted ? 'bg-sage' : 'bg-sage/40'}`}
            style={{
              shadowColor: '#6B8068',
              shadowOpacity: accepted ? 0.32 : 0,
              shadowRadius: 20,
              shadowOffset: { width: 0, height: 6 },
            }}
          >
            {saving ? (
              <ActivityIndicator color="#ECE3CE" />
            ) : (
              <>
                <Sparkles size={18} color="#ECE3CE" strokeWidth={1.5} />
                <Text className="font-body-bold text-[15px] text-cream tracking-wide">Inizia</Text>
              </>
            )}
          </Pressable>
        </View>
      </ScrollView>
    </SafeAreaView>
  );
}

function Section({ icon, title, subtitle, children }: {
  icon: React.ReactNode; title: string; subtitle: string; children: React.ReactNode;
}) {
  return (
    <View className="px-6 mt-6">
      <View className="flex-row items-center gap-3 mb-3">
        <View className="w-10 h-10 rounded-full bg-white dark:bg-dark-surface items-center justify-center" style={{ shadowColor: '#6B8068', shadowOpacity: 0.1, shadowRadius: 8, shadowOffset: { width: 0, height: 2 } }}>
          {icon}
        </View>
        <View className="flex-1">
          <Text className="font-display-semi text-[18px] text-ink dark:text-cream">{title}</Text>
          <Text className="font-body-medium text-[12px] text-ink-muted leading-relaxed">{subtitle}</Text>
        </View>
      </View>
      <View className="bg-white dark:bg-dark-surface rounded-3xl overflow-hidden">
        {children}
      </View>
    </View>
  );
}

function Toggle({ label, value, onToggle }: { label: string; value: boolean; onToggle: () => void }) {
  return (
    <Pressable onPress={onToggle} className="flex-row items-center px-5 py-3.5 border-b border-divider" style={{ borderColor: 'rgba(31,36,25,0.06)' }}>
      <Text className="flex-1 font-body-semi text-[14px] text-ink dark:text-cream">{label}</Text>
      <View className={`w-12 h-7 rounded-full justify-center px-0.5 ${value ? 'bg-sage' : 'bg-divider'}`}>
        <View className={`w-6 h-6 rounded-full bg-white ${value ? 'self-end' : 'self-start'}`} style={{ shadowColor: '#000', shadowOpacity: 0.1, shadowRadius: 2 }} />
      </View>
    </Pressable>
  );
}

function PrivacyOption({ label, sub, selected, onPress }: { label: string; sub: string; selected: boolean; onPress: () => void }) {
  return (
    <Pressable onPress={onPress} className="flex-row items-center px-5 py-4 gap-3 border-b border-divider" style={{ borderColor: 'rgba(31,36,25,0.06)' }}>
      <View className={`w-5 h-5 rounded-full border-2 items-center justify-center ${selected ? 'border-sage' : 'border-divider'}`}>
        {selected && <View className="w-2.5 h-2.5 rounded-full bg-sage" />}
      </View>
      <View className="flex-1">
        <Text className="font-body-semi text-[14px] text-ink dark:text-cream">{label}</Text>
        <Text className="font-body-medium text-[12px] text-ink-muted">{sub}</Text>
      </View>
    </Pressable>
  );
}
