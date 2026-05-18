import { useState } from 'react';
import { ActivityIndicator, Alert, KeyboardAvoidingView, Platform, Pressable, ScrollView, Text, TextInput, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { router } from 'expo-router';
import { ChevronLeft } from 'lucide-react-native';

import { me } from '@/lib/api';
import { useAuthStore } from '@/lib/auth-store';

export default function EditProfile() {
  const user = useAuthStore((s) => s.user);
  const setUser = useAuthStore((s) => s.setUser);

  const [name, setName] = useState(user?.name ?? '');
  const [bio, setBio]   = useState(user?.bio ?? '');
  const [showInMatchmaking, setShowInMatchmaking] = useState(user?.show_in_matchmaking ?? true);
  const [privacy, setPrivacy] = useState<'public' | 'club_only' | 'friends_only'>(user?.privacy_profile ?? 'club_only');

  const [saving, setSaving] = useState(false);

  const save = async () => {
    if (name.trim().length < 2) {
      Alert.alert('Nome troppo corto', 'Almeno 2 caratteri.');
      return;
    }
    setSaving(true);
    try {
      const updated = await me.update({
        name: name.trim(),
        bio: bio.trim() || null as any,
        privacy_profile: privacy,
        show_in_matchmaking: showInMatchmaking,
      });
      setUser(updated);
      router.back();
    } catch {
      Alert.alert('Errore', 'Salvataggio fallito. Riprova.');
    } finally {
      setSaving(false);
    }
  };

  return (
    <SafeAreaView className="flex-1 bg-cream-light dark:bg-dark-bg" edges={['top']}>
      <View className="flex-row items-center justify-between px-5 pt-3 pb-2">
        <Pressable onPress={() => router.back()} className="w-10 h-10 items-center justify-center -ml-1">
          <ChevronLeft size={26} color="#1F2419" strokeWidth={1.5} />
        </Pressable>
        <Text className="font-display-italic text-[18px] text-ink dark:text-cream">Profilo</Text>
        <Pressable onPress={save} disabled={saving}>
          {saving ? <ActivityIndicator color="#6B8068" /> : <Text className="font-body-bold text-[14px] text-sage-dark">Salva</Text>}
        </Pressable>
      </View>

      <KeyboardAvoidingView
        behavior={Platform.OS === 'ios' ? 'padding' : undefined}
        className="flex-1"
        keyboardVerticalOffset={Platform.OS === 'ios' ? 60 : 0}
      >
      <ScrollView contentContainerStyle={{ paddingBottom: 60 }} keyboardShouldPersistTaps="handled">
        <View className="px-6 mt-4">
          <Label>Nome</Label>
          <Field>
            <TextInput
              value={name}
              onChangeText={setName}
              placeholder="Il tuo nome"
              placeholderTextColor="#7A7E72"
              className="font-body-semi text-[16px] text-ink dark:text-cream"
            />
          </Field>
        </View>

        <View className="px-6 mt-4">
          <Label>Bio</Label>
          <Field>
            <TextInput
              value={bio}
              onChangeText={setBio}
              placeholder="Una frase su di te (es. 'Adoro il dritto lungolinea')"
              placeholderTextColor="#7A7E72"
              multiline
              maxLength={200}
              className="font-body-medium text-[15px] text-ink dark:text-cream"
              style={{ minHeight: 60 }}
            />
          </Field>
        </View>

        <View className="px-6 mt-6">
          <Label>Privacy del profilo</Label>
          <View className="bg-white dark:bg-dark-surface rounded-3xl overflow-hidden">
            <PrivacyOption label="Pubblico"   sub="Chiunque può vederti"     selected={privacy === 'public'}       onPress={() => setPrivacy('public')} />
            <Divider />
            <PrivacyOption label="Solo soci"  sub="Solo membri del circolo"  selected={privacy === 'club_only'}    onPress={() => setPrivacy('club_only')} />
            <Divider />
            <PrivacyOption label="Solo amici" sub="Solo i tuoi amici"        selected={privacy === 'friends_only'} onPress={() => setPrivacy('friends_only')} />
          </View>
        </View>

        <View className="px-6 mt-6">
          <Label>Matchmaking</Label>
          <Pressable
            onPress={() => setShowInMatchmaking(!showInMatchmaking)}
            className="bg-white dark:bg-dark-surface rounded-3xl px-5 py-4 flex-row items-center"
          >
            <View className="flex-1">
              <Text className="font-body-semi text-[15px] text-ink dark:text-cream">Mostrami nel matchmaking</Text>
              <Text className="font-body-medium text-[12px] text-ink-muted mt-0.5">
                Altri giocatori possono propormi una partita
              </Text>
            </View>
            <Toggle value={showInMatchmaking} />
          </Pressable>
        </View>
      </ScrollView>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

function Label({ children }: { children: React.ReactNode }) {
  return <Text className="font-body-bold text-[10px] tracking-widest uppercase text-ink-muted mb-2">{children}</Text>;
}

function Field({ children }: { children: React.ReactNode }) {
  return <View className="bg-white dark:bg-dark-surface rounded-2xl px-4 py-3">{children}</View>;
}

function PrivacyOption({
  label, sub, selected, onPress,
}: { label: string; sub: string; selected: boolean; onPress: () => void }) {
  return (
    <Pressable onPress={onPress} className="flex-row items-center px-5 py-4 gap-3">
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

function Toggle({ value }: { value: boolean }) {
  return (
    <View className={`w-12 h-7 rounded-full ${value ? 'bg-sage' : 'bg-divider'} justify-center px-0.5`}>
      <View className={`w-6 h-6 rounded-full bg-white shadow ${value ? 'self-end' : 'self-start'}`} />
    </View>
  );
}

function Divider() {
  return <View style={{ height: 1, backgroundColor: 'rgba(31,36,25,0.06)', marginHorizontal: 20 }} />;
}
