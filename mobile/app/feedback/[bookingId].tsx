import { useState } from 'react';
import { ActivityIndicator, Alert, KeyboardAvoidingView, Platform, Pressable, ScrollView, Text, TextInput, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { router, useLocalSearchParams } from 'expo-router';
import { LinearGradient } from 'expo-linear-gradient';
import { ChevronLeft, Star } from 'lucide-react-native';

import { feedback } from '@/lib/api';

const RATING_LABELS = ['', 'Pessima', 'Sotto le aspettative', 'Buona', 'Molto buona', 'Eccellente'];

export default function FeedbackForm() {
  const { bookingId } = useLocalSearchParams<{ bookingId: string }>();
  const [rating, setRating] = useState(0);
  const [comment, setComment] = useState('');
  const [submitting, setSubmitting] = useState(false);

  const canSubmit = rating > 0 && !submitting;

  const submit = async () => {
    if (!canSubmit) return;
    setSubmitting(true);
    try {
      await feedback.submit({
        rating,
        comment: comment.trim() || undefined,
        booking_id: bookingId ? Number(bookingId) : undefined,
        type: bookingId ? 'post_match' : 'spontaneous',
      });
      Alert.alert('Grazie!', 'Il tuo feedback è prezioso per il circolo.', [
        { text: 'OK', onPress: () => router.back() },
      ]);
    } catch {
      Alert.alert('Errore', 'Invio fallito. Riprova.');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <SafeAreaView className="flex-1 bg-cream-light dark:bg-dark-bg" edges={['top']}>
      <View className="flex-row items-center justify-between px-5 pt-3 pb-2">
        <Pressable onPress={() => router.back()} className="w-10 h-10 items-center justify-center -ml-1">
          <ChevronLeft size={26} color="#1F2419" strokeWidth={1.5} />
        </Pressable>
        <Text className="font-display-italic text-[18px] text-ink dark:text-cream">Feedback</Text>
        <View className="w-10" />
      </View>

      <KeyboardAvoidingView
        behavior={Platform.OS === 'ios' ? 'padding' : undefined}
        className="flex-1"
        keyboardVerticalOffset={Platform.OS === 'ios' ? 60 : 0}
      >
      <ScrollView contentContainerStyle={{ paddingBottom: 60 }} keyboardShouldPersistTaps="handled">
        {/* Hero */}
        <View className="px-7 pt-6 pb-4">
          <Text className="font-body-bold text-[10px] tracking-widest uppercase text-ink-muted">
            {bookingId ? 'Come è andata?' : 'Lascia un feedback'}
          </Text>
          <Text className="font-display-italic text-[28px] text-ink dark:text-cream mt-2 leading-[34px]">
            La tua opinione conta.
          </Text>
          <Text className="font-body-medium text-[13px] text-ink-muted mt-2 leading-relaxed">
            Aiutaci a migliorare il circolo. Solo l'amministrazione la vede.
          </Text>
        </View>

        {/* Star rating */}
        <View className="px-7 mt-4">
          <Text className="font-body-bold text-[10px] tracking-widest uppercase text-ink-muted mb-3 text-center">
            Voto
          </Text>
          <View className="flex-row justify-center gap-3">
            {[1, 2, 3, 4, 5].map((n) => (
              <Pressable key={n} onPress={() => setRating(n)} className="p-1">
                <Star
                  size={42}
                  color={n <= rating ? '#C89B5A' : '#D8CEB6'}
                  fill={n <= rating ? '#C89B5A' : 'none'}
                  strokeWidth={1.5}
                />
              </Pressable>
            ))}
          </View>
          {rating > 0 && (
            <Text className="font-display-italic text-[16px] text-ink dark:text-cream text-center mt-4">
              {RATING_LABELS[rating]}
            </Text>
          )}
        </View>

        {/* Comment */}
        <View className="px-7 mt-7">
          <Text className="font-body-bold text-[10px] tracking-widest uppercase text-ink-muted mb-2">
            Commento (opzionale)
          </Text>
          <View
            className="bg-white dark:bg-dark-surface rounded-2xl px-4 py-3"
            style={{ shadowColor: '#6B8068', shadowOpacity: 0.06, shadowRadius: 10, shadowOffset: { width: 0, height: 2 } }}
          >
            <TextInput
              value={comment}
              onChangeText={setComment}
              placeholder="Cosa è andato bene? Cosa migliorare?"
              placeholderTextColor="#7A7E72"
              multiline
              maxLength={1000}
              className="font-body-medium text-[14px] text-ink dark:text-cream"
              style={{ minHeight: 100, textAlignVertical: 'top' }}
            />
          </View>
          <Text className="font-body-medium text-[11px] text-ink-muted mt-2 text-right">
            {comment.length}/1000
          </Text>
        </View>

        {/* CTA */}
        <View className="px-6 mt-8">
          {canSubmit ? (
            <Pressable onPress={submit}>
              <LinearGradient
                colors={['#6B8068', '#8AA086']}
                start={{ x: 0, y: 0 }} end={{ x: 1, y: 1 }}
                style={{ borderRadius: 999, paddingVertical: 17, alignItems: 'center', shadowColor: '#6B8068', shadowOpacity: 0.32, shadowRadius: 20, shadowOffset: { width: 0, height: 6 } }}
              >
                {submitting ? <ActivityIndicator color="#ECE3CE" /> : (
                  <Text className="font-body-bold text-[15px] text-cream tracking-wide">Invia feedback</Text>
                )}
              </LinearGradient>
            </Pressable>
          ) : (
            <View className="w-full py-[17px] rounded-full items-center bg-sage/40">
              <Text className="font-body-bold text-[15px] text-cream tracking-wide">Scegli un voto</Text>
            </View>
          )}
        </View>
      </ScrollView>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}
