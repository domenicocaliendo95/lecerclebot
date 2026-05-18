import { useState } from 'react';
import { ActivityIndicator, Alert, KeyboardAvoidingView, Platform, Pressable, ScrollView, Text, TextInput, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { router } from 'expo-router';
import * as ImagePicker from 'expo-image-picker';
import { Bell, Camera, ChevronLeft, Lock, Sparkles } from 'lucide-react-native';

import { me } from '@/lib/api';
import { useAuthStore } from '@/lib/auth-store';
import { Avatar } from '@/components/Avatar';

const DEFAULT_PREFS = {
  reminders: true,
  match_invites: true,
  results_request: true,
  chat: true,
  social: true,
  tournaments: true,
  marketing: false,
};

type Step = 'name' | 'prefs';

export default function Onboarding() {
  const user = useAuthStore((s) => s.user);
  const setUser = useAuthStore((s) => s.setUser);

  const [step, setStep] = useState<Step>('name');

  // Step 1: name + avatar
  const initialName = !user?.name || user.name === 'Nuovo Giocatore' ? '' : user.name;
  const [name, setName] = useState(initialName);
  const [uploading, setUploading] = useState(false);

  // Step 2: prefs + privacy + T&C
  const [prefs, setPrefs] = useState(DEFAULT_PREFS);
  const [privacy, setPrivacy] = useState<'public' | 'club_only' | 'friends_only'>('club_only');
  const [accepted, setAccepted] = useState(false);
  const [saving, setSaving] = useState(false);

  // ── Step 1 actions ──

  const pickAvatar = async () => {
    Alert.alert('Foto profilo', '', [
      { text: 'Annulla', style: 'cancel' },
      { text: 'Fotocamera', onPress: () => pickFromSource('camera') },
      { text: 'Libreria', onPress: () => pickFromSource('library') },
    ]);
  };

  const pickFromSource = async (src: 'camera' | 'library') => {
    const perm = src === 'camera'
      ? await ImagePicker.requestCameraPermissionsAsync()
      : await ImagePicker.requestMediaLibraryPermissionsAsync();
    if (!perm.granted) {
      Alert.alert('Permesso negato', 'Vai nelle impostazioni per abilitare.');
      return;
    }
    const result = src === 'camera'
      ? await ImagePicker.launchCameraAsync({ allowsEditing: true, aspect: [1, 1], quality: 0.85 })
      : await ImagePicker.launchImageLibraryAsync({
          mediaTypes: ImagePicker.MediaTypeOptions.Images,
          allowsEditing: true, aspect: [1, 1], quality: 0.85,
        });
    if (result.canceled || !result.assets?.[0]) return;

    setUploading(true);
    try {
      const { avatar_url } = await me.uploadAvatar(result.assets[0].uri);
      if (user) setUser({ ...user, avatar_url });
    } catch {
      Alert.alert('Errore', 'Upload fallito.');
    } finally {
      setUploading(false);
    }
  };

  const goToPrefs = async () => {
    if (name.trim().length < 2) {
      Alert.alert('Manca il nome', 'Inserisci almeno 2 caratteri.');
      return;
    }
    setSaving(true);
    try {
      const updated = await me.update({ name: name.trim() });
      setUser(updated);
      setStep('prefs');
    } catch {
      Alert.alert('Errore', 'Salvataggio nome fallito. Riprova.');
    } finally {
      setSaving(false);
    }
  };

  // ── Step 2 action ──

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

  // ── Render ──

  const handleSignOut = () => {
    Alert.alert('Esci?', "Tornerai alla schermata di login.", [
      { text: 'Annulla', style: 'cancel' },
      { text: 'Esci', style: 'destructive', onPress: () => useAuthStore.getState().signOut() },
    ]);
  };

  if (step === 'name') {
    return <StepName
      user={user}
      name={name}
      setName={setName}
      uploading={uploading}
      onPickAvatar={pickAvatar}
      onNext={goToPrefs}
      onSignOut={handleSignOut}
      saving={saving}
    />;
  }

  return <StepPrefs
    name={name}
    prefs={prefs}
    setPrefs={setPrefs}
    privacy={privacy}
    setPrivacy={setPrivacy}
    accepted={accepted}
    setAccepted={setAccepted}
    saving={saving}
    onBack={() => setStep('name')}
    onFinish={finish}
  />;
}

// ════════════════════════════════════════════════════════════════════════
// STEP 1 — Nome + Avatar
// ════════════════════════════════════════════════════════════════════════

function StepName({
  user, name, setName, uploading, onPickAvatar, onNext, onSignOut, saving,
}: any) {
  const canContinue = name.trim().length >= 2 && !saving;

  return (
    <SafeAreaView className="flex-1 bg-cream-light dark:bg-dark-bg" edges={['top']}>
      <KeyboardAvoidingView
        behavior={Platform.OS === 'ios' ? 'padding' : undefined}
        className="flex-1"
      >
        <ScrollView
          contentContainerStyle={{ paddingBottom: 40 }}
          keyboardShouldPersistTaps="handled"
          showsVerticalScrollIndicator={false}
        >
        {/* Top bar — esci (siamo già autenticati, ma può uscire) */}
        <View className="flex-row justify-between items-center px-5 pt-2">
          <View className="w-11" />
          <View className="flex-row gap-2">
            <View className="w-8 h-1.5 rounded-full bg-sage" />
            <View className="w-8 h-1.5 rounded-full" style={{ backgroundColor: 'rgba(31,36,25,0.12)' }} />
          </View>
          <Pressable onPress={onSignOut} className="w-11 h-11 items-end justify-center">
            <Text className="font-body-medium text-[12px] text-ink-muted">Esci</Text>
          </Pressable>
        </View>

        {/* Hero */}
        <View className="items-center px-7 pt-10 pb-6">
          <Text className="font-body-bold text-[10px] tracking-widest uppercase text-ink-muted">
            Passo 1 di 2
          </Text>
          <Text className="font-display-italic text-[26px] text-ink dark:text-cream text-center mt-4">
            Iniziamo dal
          </Text>
          <Text className="font-script text-[56px] text-sage-dark dark:text-sage leading-[58px] -mt-1">
            tuo nome
          </Text>
          <Text className="font-body-medium text-[13px] text-ink-muted text-center mt-3 leading-relaxed">
            È quello che gli altri soci vedranno{'\n'}sulle prenotazioni e in classifica.
          </Text>
        </View>

        {/* Avatar pickable */}
        <View className="items-center mb-7">
          <Pressable onPress={onPickAvatar} disabled={uploading} className="relative">
            <Avatar url={user?.avatar_url} name={name || '?'} size={112} bordered />
            {uploading ? (
              <View className="absolute inset-0 items-center justify-center rounded-full bg-black/40">
                <ActivityIndicator color="#ECE3CE" />
              </View>
            ) : (
              <View
                className="absolute bottom-0 right-0 w-10 h-10 rounded-full bg-sage items-center justify-center"
                style={{ shadowColor: '#6B8068', shadowOpacity: 0.4, shadowRadius: 10, shadowOffset: { width: 0, height: 4 } }}
              >
                <Camera size={18} color="#ECE3CE" strokeWidth={2} />
              </View>
            )}
          </Pressable>
          <Text className="font-body-medium text-[12px] text-ink-muted mt-3">
            {user?.avatar_url ? 'Tocca per cambiare' : 'Aggiungi una foto (opzionale)'}
          </Text>
        </View>

        {/* Name input */}
        <View className="px-7">
          <Text className="font-body-bold text-[10px] tracking-widest uppercase text-ink-muted mb-2">
            Come ti chiami?
          </Text>
          <View
            className="bg-white dark:bg-dark-surface rounded-2xl px-4 py-3"
            style={{ shadowColor: '#6B8068', shadowOpacity: 0.08, shadowRadius: 12, shadowOffset: { width: 0, height: 3 } }}
          >
            <TextInput
              value={name}
              onChangeText={setName}
              placeholder="Es. Marco Rossi"
              placeholderTextColor="#7A7E72"
              autoFocus
              autoCapitalize="words"
              maxLength={60}
              returnKeyType="next"
              onSubmitEditing={canContinue ? onNext : undefined}
              className="font-display-semi text-[20px] text-ink dark:text-cream"
            />
          </View>
          <Text className="font-body-medium text-[11px] text-ink-muted mt-2 leading-relaxed">
            Nome e cognome se vuoi essere riconoscibile, o solo il nome — come preferisci.
          </Text>
        </View>

        {/* CTA */}
        <View className="px-6 mt-8">
          <Pressable
            disabled={!canContinue}
            onPress={onNext}
            className={`w-full py-[17px] rounded-full items-center ${canContinue ? 'bg-sage' : 'bg-sage/40'}`}
            style={{
              shadowColor: '#6B8068',
              shadowOpacity: canContinue ? 0.32 : 0,
              shadowRadius: 20,
              shadowOffset: { width: 0, height: 6 },
            }}
          >
            {saving ? (
              <ActivityIndicator color="#ECE3CE" />
            ) : (
              <Text className="font-body-bold text-[15px] text-cream tracking-wide">Continua</Text>
            )}
          </Pressable>
        </View>
      </ScrollView>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

// ════════════════════════════════════════════════════════════════════════
// STEP 2 — Prefs + Privacy + T&C
// ════════════════════════════════════════════════════════════════════════

function StepPrefs({
  name, prefs, setPrefs, privacy, setPrivacy, accepted, setAccepted, saving, onBack, onFinish,
}: any) {
  const firstName = String(name).trim().split(/\s+/)[0];

  return (
    <SafeAreaView className="flex-1 bg-cream-light dark:bg-dark-bg" edges={['top']}>
      <KeyboardAvoidingView
        behavior={Platform.OS === 'ios' ? 'padding' : undefined}
        className="flex-1"
      >
        <ScrollView
          contentContainerStyle={{ paddingBottom: 40 }}
          keyboardShouldPersistTaps="handled"
          showsVerticalScrollIndicator={false}
        >
        {/* Top bar with back button + dots */}
        <View className="flex-row justify-between items-center px-5 pt-2">
          <Pressable
            onPress={onBack}
            className="w-11 h-11 rounded-full bg-white dark:bg-dark-surface items-center justify-center"
            style={{ shadowColor: '#6B8068', shadowOpacity: 0.08, shadowRadius: 8, shadowOffset: { width: 0, height: 2 } }}
          >
            <ChevronLeft size={22} color="#1F2419" strokeWidth={1.5} />
          </Pressable>
          <View className="flex-row gap-2">
            <View className="w-8 h-1.5 rounded-full bg-sage/40" />
            <View className="w-8 h-1.5 rounded-full bg-sage" />
          </View>
          <View className="w-11" />
        </View>

        {/* Hero — titolo + script su righe separate per evitare clipping */}
        <View className="items-center px-7 pt-10 pb-6">
          <Text className="font-body-bold text-[10px] tracking-widest uppercase text-ink-muted">
            Passo 2 di 2
          </Text>
          <Text className="font-display-italic text-[26px] text-ink dark:text-cream text-center mt-4">
            Benvenuto,
          </Text>
          <Text className="font-script text-[60px] text-sage-dark dark:text-sage leading-[62px] -mt-1">
            {firstName}
          </Text>
          <Text className="font-body-medium text-[13px] text-ink-muted text-center mt-2 leading-relaxed">
            Due ultimi dettagli e sei dentro.
          </Text>
        </View>

        {/* Notifications */}
        <Section
          icon={<Bell size={20} color="#A47A3F" strokeWidth={1.5} />}
          title="Cosa vuoi ricevere?"
          subtitle="Puoi cambiare tutto dalle impostazioni."
        >
          <Toggle label="Promemoria partite"      value={prefs.reminders}       onToggle={() => setPrefs({ ...prefs, reminders: !prefs.reminders })} />
          <Toggle label="Inviti partita"          value={prefs.match_invites}   onToggle={() => setPrefs({ ...prefs, match_invites: !prefs.match_invites })} />
          <Toggle label="Richieste risultato"     value={prefs.results_request} onToggle={() => setPrefs({ ...prefs, results_request: !prefs.results_request })} />
          <Toggle label="Chat"                    value={prefs.chat}            onToggle={() => setPrefs({ ...prefs, chat: !prefs.chat })} />
          <Toggle label="Tornei ed eventi"        value={prefs.tournaments}     onToggle={() => setPrefs({ ...prefs, tournaments: !prefs.tournaments, social: !prefs.tournaments })} />
          <Toggle label="Promozioni del circolo"  value={prefs.marketing}       onToggle={() => setPrefs({ ...prefs, marketing: !prefs.marketing })} />
        </Section>

        {/* Privacy */}
        <Section
          icon={<Lock size={20} color="#4F6450" strokeWidth={1.5} />}
          title="Chi può vederti?"
          subtitle="Decidi chi vede profilo e statistiche."
        >
          <PrivacyOption label="Pubblico"   sub="Chiunque può vederti"     selected={privacy === 'public'}       onPress={() => setPrivacy('public')} />
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
              <Text className="text-sage-dark dark:text-sage underline">Termini di servizio</Text>{' '}e la{' '}
              <Text className="text-sage-dark dark:text-sage underline">Privacy Policy</Text>{' '}di Le Cercle Club.
            </Text>
          </Pressable>
        </View>

        {/* CTA */}
        <View className="px-6 mt-6">
          <Pressable
            disabled={!accepted || saving}
            onPress={onFinish}
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
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

// ── Sub-components ───────────────────────────────────────────────────

function Section({ icon, title, subtitle, children }: any) {
  return (
    <View className="px-6 mt-6">
      <View className="flex-row items-center gap-3 mb-3">
        <View
          className="w-10 h-10 rounded-full bg-white dark:bg-dark-surface items-center justify-center"
          style={{ shadowColor: '#6B8068', shadowOpacity: 0.1, shadowRadius: 8, shadowOffset: { width: 0, height: 2 } }}
        >
          {icon}
        </View>
        <View className="flex-1">
          <Text className="font-display-semi text-[18px] text-ink dark:text-cream">{title}</Text>
          <Text className="font-body-medium text-[12px] text-ink-muted leading-relaxed">{subtitle}</Text>
        </View>
      </View>
      <View className="bg-white dark:bg-dark-surface rounded-3xl overflow-hidden">{children}</View>
    </View>
  );
}

function Toggle({ label, value, onToggle }: any) {
  return (
    <Pressable
      onPress={onToggle}
      className="flex-row items-center px-5 py-3.5 border-b border-divider"
      style={{ borderColor: 'rgba(31,36,25,0.06)' }}
    >
      <Text className="flex-1 font-body-semi text-[14px] text-ink dark:text-cream">{label}</Text>
      <View className={`w-12 h-7 rounded-full justify-center px-0.5 ${value ? 'bg-sage' : 'bg-divider'}`}>
        <View
          className={`w-6 h-6 rounded-full bg-white ${value ? 'self-end' : 'self-start'}`}
          style={{ shadowColor: '#000', shadowOpacity: 0.1, shadowRadius: 2 }}
        />
      </View>
    </Pressable>
  );
}

function PrivacyOption({ label, sub, selected, onPress }: any) {
  return (
    <Pressable
      onPress={onPress}
      className="flex-row items-center px-5 py-4 gap-3 border-b border-divider"
      style={{ borderColor: 'rgba(31,36,25,0.06)' }}
    >
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
