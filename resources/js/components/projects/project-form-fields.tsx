import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';

type ProjectFormFieldsProps = {
    errors: Record<string, string | undefined>;
    defaults?: {
        key?: string;
        name?: string;
        description?: string | null;
    };
};

/**
 * Key/name/description fields shared by the create and edit project
 * dialogs, with inline Spanish validation errors.
 */
export function ProjectFormFields({
    errors,
    defaults,
}: ProjectFormFieldsProps) {
    return (
        <div className="flex flex-col gap-4">
            <div className="grid gap-2">
                <Label htmlFor="key">Clave</Label>
                <Input
                    id="key"
                    name="key"
                    required
                    maxLength={10}
                    placeholder="ENG"
                    defaultValue={defaults?.key}
                    className="uppercase"
                    aria-invalid={Boolean(errors.key)}
                />
                {errors.key ? (
                    <p className="text-sm text-destructive">{errors.key}</p>
                ) : null}
            </div>

            <div className="grid gap-2">
                <Label htmlFor="name">Nombre</Label>
                <Input
                    id="name"
                    name="name"
                    required
                    placeholder="Ingeniería"
                    defaultValue={defaults?.name}
                    aria-invalid={Boolean(errors.name)}
                />
                {errors.name ? (
                    <p className="text-sm text-destructive">{errors.name}</p>
                ) : null}
            </div>

            <div className="grid gap-2">
                <Label htmlFor="description">Descripción</Label>
                <Textarea
                    id="description"
                    name="description"
                    placeholder="¿De qué se trata este proyecto?"
                    defaultValue={defaults?.description ?? ''}
                    aria-invalid={Boolean(errors.description)}
                />
                {errors.description ? (
                    <p className="text-sm text-destructive">
                        {errors.description}
                    </p>
                ) : null}
            </div>
        </div>
    );
}
