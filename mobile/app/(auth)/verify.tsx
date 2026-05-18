import { useEffect, useRef, useState } from 'react';
import { ActivityIndicator, Alert, Pressable, Text, TextInput, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { router, useLocalSearchParams } from 'expo-router';
import { ChevronLeft } from 'lucide-react-native';
import axios from 'axios';

import { auth } from '@/lib/api';
import { useAuthStore } from '@/lib/auth-store';

const CODE_LENGTH = 6;

export default function Verify() {
  const params = useLocalSearchParams<{ phone: string; maskedPhone?: string; expiresAt?: string }>();
  const phone = params.phone ?? '';
  const maskedPhone = params.maskedPhone ?? phone;

  const [code, setCode] = useState('');
  const [loading, setLoading] = useState(false);
  const [secondsLeft, setSecondsLeft] = useState(60);
  const inputRef = useRef<TextInput>(null);

  const setAuth = useAuthStore((s) => s.setAuth);

  useEffect(() => {
    inputRef.current?.focus();
    const interval = setInterval(() => {
      setSecondsLeft((s) => (s > 0 ? s - 1 : 0));
    }, 1000);
    return () => clearInterval(interval);
  }, []);

  useEffect(() => {
    if (code.length === CODE_LENGTH) {
      void handleVerify(code);
    }
  }, [code]);

  async function handleVerify(c: string) {
    if (loading) return;
    setLoading(true);
    try {
      const res = await auth.verifyOtp(phone, c);
      await setAuth(res.token, res.user);
      if (res.needs_app_onboarding) {
        router.replace('/onboarding');
      } else {
        router.replace('/(tabs)');
      }
    } catch (err) {
      const message = axios.isAxiosError(err)
        ? err.response?.data?.error?.message ?? 'Codice non valido.'
        : 'Errore inatteso.';
      Alert.alert('Codice errato', message);
      setCode('');
      inputRef.current?.focus();
    } finally {
      setLoading(false);
    }
  }

  async function handleResend() {
    if (secondsLeft > 0) return;
    try {
      await auth.requestOtp(phone);
      setSecondsLeft(60);
      Alert.alert('Codice reinviato', 'Controlla WhatsApp.');
    } catch {
      Alert.alert('Errore', 'Non sono riuscito a reinviare. Riprova tra poco.');
    }
  }

  return (
    <SafeAreaView className="flex-1 bg-cream-light dark:bg-dark-bg">
      <View className="flex-1 px-7 pt-2">
        <Pressable
          onPress={() => router.back()}
          className="w-11 h-11 rounded-full bg-white dark:bg-dark-surface items-center justify-center self-start"
          style={{ shadowColor: '#6B8068', shadowOpacity: 0.08, shadowRadius: 8, shadowOffset: { width: 0, height: 2 } }}
        >
          <ChevronLeft size={22} color="#1F2419" strokeWidth={1.5} />
        </Pressable>

        <View className="mt-8 mb-9">
          <Text className="font-display-italic text-[32px] text-ink dark:text-cream leading-[34px]">
            Inserisci il codice
          </Text>
          <Text className="font-body-medium text-[14px] text-ink-muted mt-2.5 leading-[22px]">
            L'ho mandato via WhatsApp a{'\n'}
            <Text className="font-body-bold text-ink dark:text-cream">{maskedPhone}</Text>
          </Text>
        </View>

        {/* Code boxes (visual) — l'input vero è hidden */}
        <Pressable onPress={() => inputRef.current?.focus()} className="flex-row gap-2 mb-6">
          {Array.from({ length: CODE_LENGTH }).map((_, i) => {
            const digit = code[i] ?? '';
            const isFocused = i === code.length;
            return (
              <View
                key={i}
                className={`flex-1 aspect-[3/4] rounded-2xl items-center justify-center bg-white dark:bg-dark-surface ${
                  isFocused ? 'border border-sage' : ''
                }`}
                style={{
                  shadowColor: '#6B8068',
                  shadowOpacity: 0.06,
                  shadowRadius: 6,
                  shadowOffset: { width: 0, height: 2 },
                }}
              >
                <Text className="font-display-semi text-[28px] text-ink dark:text-cream">{digit}</Text>
              </View>
            );
          })}
        </Pressable>

        {/* Hidden input */}
        <TextInput
          ref={inputRef}
          value={code}
          onChangeText={(v) => setCode(v.replace(/\D/g, '').slice(0, CODE_LENGTH))}
          keyboardType="number-pad"
          maxLength={CODE_LENGTH}
          textContentType="oneTimeCode"
          autoComplete="sms-otp"
          editable={!loading}
          style={{ position: 'absolute', opacity: 0, height: 1, width: 1 }}
        />

        {loading && (
          <View className="items-center mb-4">
            <ActivityIndicator color="#6B8068" />
          </View>
        )}

        {/* Resend */}
        <View className="items-center mt-4">
          {secondsLeft > 0 ? (
            <Text className="text-[13px] text-ink-muted">
              Reinvia tra <Text className="font-body-bold">{secondsLeft}s</Text>
            </Text>
          ) : (
            <Pressable onPress={handleResend}>
              <Text className="text-[13px] text-sage-dark dark:text-sage font-body-bold">
                Reinvia il codice
              </Text>
            </Pressable>
          )}
        </View>

        <View className="mt-auto items-center mb-4">
          <Text className="text-[12px] text-ink-muted">
            Non ricevi nulla?{' '}
            <Text className="text-ocra-dark dark:text-ocra font-body-bold">
              Invia via email
            </Text>
          </Text>
        </View>
      </View>
    </SafeAreaView>
  );
}
