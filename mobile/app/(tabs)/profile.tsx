import { useState } from 'react';
import { ActivityIndicator, Alert, Pressable, ScrollView, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { router } from 'expo-router';
import * as ImagePicker from 'expo-image-picker';
import {
  Bell,
  Camera,
  ChevronRight,
  LogOut,
  Moon,
  Settings,
  Trash2,
  User as UserIcon,
  Users as UsersIcon,
} from 'lucide-react-native';

import { me } from '@/lib/api';
import { useAuthStore } from '@/lib/auth-store';
import { Avatar } from '@/components/Avatar';

export default function Profile() {
  const user = useAuthStore((s) => s.user);
  const setUser = useAuthStore((s) => s.setUser);
  const signOut = useAuthStore((s) => s.signOut);
  const [uploadingAvatar, setUploadingAvatar] = useState(false);

  if (!user) return null;

  const matches = user.matches_played ?? 0;
  const wins = user.matches_won ?? 0;
  const winRate = matches > 0 ? Math.round((wins / matches) * 100) : 0;

  const confirmSignOut = () => {
    Alert.alert('Esci', 'Sicuro di voler uscire?', [
      { text: 'Annulla', style: 'cancel' },
      { text: 'Esci', style: 'destructive', onPress: () => signOut() },
    ]);
  };

  const pickAvatar = async () => {
    Alert.alert('Foto profilo', 'Da dove vuoi prendere la foto?', [
      { text: 'Annulla', style: 'cancel' },
      { text: 'Fotocamera', onPress: () => pickFromSource('camera') },
      { text: 'Libreria', onPress: () => pickFromSource('library') },
      ...(user.avatar_url ? [{ text: 'Rimuovi', style: 'destructive' as const, onPress: removeAvatar }] : []),
    ]);
  };

  const pickFromSource = async (source: 'camera' | 'library') => {
    const perm = source === 'camera'
      ? await ImagePicker.requestCameraPermissionsAsync()
      : await ImagePicker.requestMediaLibraryPermissionsAsync();

    if (!perm.granted) {
      Alert.alert('Permesso negato', 'Vai nelle impostazioni del sistema per abilitare.');
      return;
    }

    const result = source === 'camera'
      ? await ImagePicker.launchCameraAsync({ allowsEditing: true, aspect: [1, 1], quality: 0.85 })
      : await ImagePicker.launchImageLibraryAsync({
          mediaTypes: ImagePicker.MediaTypeOptions.Images,
          allowsEditing: true, aspect: [1, 1], quality: 0.85,
        });

    if (result.canceled || !result.assets?.[0]) return;

    setUploadingAvatar(true);
    try {
      const { avatar_url } = await me.uploadAvatar(result.assets[0].uri);
      setUser({ ...user, avatar_url });
    } catch {
      Alert.alert('Errore', 'Upload fallito. Riprova.');
    } finally {
      setUploadingAvatar(false);
    }
  };

  const removeAvatar = async () => {
    setUploadingAvatar(true);
    try {
      await me.deleteAvatar();
      setUser({ ...user, avatar_url: null });
    } catch {
      Alert.alert('Errore', 'Operazione fallita.');
    } finally {
      setUploadingAvatar(false);
    }
  };

  return (
    <SafeAreaView className="flex-1 bg-cream-light dark:bg-dark-bg" edges={['top']}>
      <ScrollView contentContainerStyle={{ paddingBottom: 40 }}>
        <View className="flex-row justify-end px-6 pt-3">
          <Pressable className="w-10 h-10 rounded-full items-center justify-center">
            <Settings size={22} color="#1F2419" strokeWidth={1.5} />
          </Pressable>
        </View>

        {/* Avatar + name */}
        <View className="items-center px-6 pb-6">
          <Pressable onPress={pickAvatar} disabled={uploadingAvatar} className="relative">
            <Avatar url={user.avatar_url} name={user.name} size={104} bordered />
            {uploadingAvatar ? (
              <View className="absolute inset-0 items-center justify-center rounded-full bg-black/40">
                <ActivityIndicator color="#ECE3CE" />
              </View>
            ) : (
              <View className="absolute bottom-0 right-0 w-9 h-9 rounded-full bg-sage items-center justify-center" style={{ shadowColor: '#6B8068', shadowOpacity: 0.35, shadowRadius: 8, shadowOffset: { width: 0, height: 3 } }}>
                <Camera size={16} color="#ECE3CE" strokeWidth={2} />
              </View>
            )}
          </Pressable>
          <Text className="font-display-semi text-[28px] text-ink dark:text-cream mt-4">{user.name}</Text>
          {user.bio ? (
            <Text className="font-display-italic text-[13px] text-ink-muted mt-1">"{user.bio}"</Text>
          ) : (
            <Text className="font-body-medium text-[12px] text-ink-muted mt-1">{user.phone}</Text>
          )}

          <View className="flex-row gap-2 mt-3">
            {user.fit_rating && <Chip label={`FIT ${user.fit_rating}`} tone="sage" />}
            {user.self_level && <Chip label={['Neofita', 'Dilettante', 'Avanzato'][user.self_level - 1] ?? 'Livello'} tone="ocra" />}
          </View>
        </View>

        {/* Stats card */}
        <View className="px-6">
          <View
            className="bg-white dark:bg-dark-surface rounded-3xl p-5"
            style={{ shadowColor: '#6B8068', shadowOpacity: 0.08, shadowRadius: 16, shadowOffset: { width: 0, height: 4 } }}
          >
            <View className="flex-row items-center">
              <Stat label="ELO" value={String(user.elo_rating ?? '—')} sub="" />
              <View className="w-px h-10 bg-divider mx-2" />
              <Stat label="Partite" value={String(matches)} sub={`${wins}W · ${matches - wins}L`} />
              <View className="w-px h-10 bg-divider mx-2" />
              <Stat label="Win rate" value={`${winRate}%`} sub="Storico" />
            </View>
          </View>
        </View>

        {/* Menu */}
        <View className="px-6 mt-6">
          <Text className="font-display-italic text-[18px] text-ink dark:text-cream mb-3">Account</Text>
          <View className="bg-white dark:bg-dark-surface rounded-3xl overflow-hidden">
            <MenuItem icon={<UserIcon size={20} color="#4F6450" strokeWidth={1.5} />} label="Modifica profilo" onPress={() => router.push('/settings/profile')} />
            <Divider />
            <MenuItem icon={<UsersIcon size={20} color="#4F6450" strokeWidth={1.5} />} label="Amici" sub="Presto" onPress={() => {}} />
            <Divider />
            <MenuItem icon={<Bell size={20} color="#A47A3F" strokeWidth={1.5} />} label="Notifiche" onPress={() => router.push('/settings/notifications')} />
            <Divider />
            <MenuItem icon={<Moon size={20} color="#4F6450" strokeWidth={1.5} />} label="Tema" sub="Auto" onPress={() => {}} />
          </View>
        </View>

        <View className="px-6 mt-6 mb-4">
          <View className="bg-white dark:bg-dark-surface rounded-3xl overflow-hidden">
            <MenuItem icon={<LogOut size={20} color="#3A4036" strokeWidth={1.5} />} label="Esci" onPress={confirmSignOut} />
            <Divider />
            <MenuItem icon={<Trash2 size={20} color="#A85B4F" strokeWidth={1.5} />} label="Elimina account" labelClass="text-danger" onPress={() => Alert.alert('Elimina account', 'Funzionalità in arrivo.')} />
          </View>
        </View>

        <View className="items-center mt-4">
          <Text className="font-body-medium text-[11px] text-ink-muted">
            Le Cercle Tennis Club · v0.1.0
          </Text>
        </View>
      </ScrollView>
    </SafeAreaView>
  );
}

function Stat({ label, value, sub }: { label: string; value: string; sub: string }) {
  return (
    <View className="flex-1 items-center">
      <Text className="font-display-semi text-[28px] text-ink dark:text-cream">{value}</Text>
      <Text className="font-body-bold text-[9px] tracking-widest uppercase text-ink-muted mt-1">{label}</Text>
      {sub ? <Text className="font-body-medium text-[10px] text-ink-muted mt-0.5">{sub}</Text> : null}
    </View>
  );
}

function Chip({ label, tone }: { label: string; tone: 'sage' | 'ocra' }) {
  const bg = tone === 'sage' ? 'bg-sage/12' : 'bg-ocra/18';
  const txt = tone === 'sage' ? 'text-sage-dark' : 'text-ocra-dark';
  return (
    <View className={`${bg} px-3 py-1 rounded-full`}>
      <Text className={`font-body-bold text-[11px] tracking-wide ${txt}`}>{label}</Text>
    </View>
  );
}

function MenuItem({
  icon, label, sub, labelClass = 'text-ink dark:text-cream', onPress,
}: { icon: React.ReactNode; label: string; sub?: string; labelClass?: string; onPress: () => void }) {
  return (
    <Pressable onPress={onPress} className="flex-row items-center gap-3 px-5 py-4">
      {icon}
      <Text className={`flex-1 font-body-medium text-[15px] ${labelClass}`}>{label}</Text>
      {sub ? <Text className="font-body-medium text-[12px] text-ink-muted">{sub}</Text> : null}
      <ChevronRight size={16} color="#7A7E72" strokeWidth={2} />
    </Pressable>
  );
}

function Divider() {
  return <View className="h-px bg-divider mx-5" style={{ backgroundColor: 'rgba(31,36,25,0.06)' }} />;
}
